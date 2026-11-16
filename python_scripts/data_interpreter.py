r"""Cisco-IOS/IOS-XE-Diagnoseausgaben in Website-Status umwandeln.

Eingabe: {"show boot": "Ausgabe des Cisco-Befehls", ...}
Rückgabe: {"status": "ok" | "error", "output": "Kurztext", "issues": [...],
            "evaluated_commands": ["show version", ...]}
Jeder Issue-Eintrag hat code, command, message und details. Die Identität
besteht aus Befehl, Regel-code und optional dem stabilen Finding.key. Bei
list[str] wird der konkrete Zeilentext gehasht: Für veränderliche Details
unbedingt Finding(key=..., detail=...) verwenden.

Eine neue Regel hinzufügen (die Fehlermeldung steht IM Dekorator):

    @command_check("show boot", "Manueller Bootmodus ist aktiviert", code="manual_boot")
    def check_manual_boot(output: str) -> re.Match[str] | None:
        return re.search(r"Manual Boot\s*[:=]\s*yes", output, re.I)

Ein re.Match liefert neben der Meldung die vollständige betroffene
Cisco-Ausgabezeile. Mit list[str] lassen sich mehrere konkrete Befunde
(z. B. Bauteilname + Zustand) melden. Für mehrere dynamische Befunde können
Finding(key="bauteil-identität", detail="konkreter Fehler") genutzt werden.
Der Schlüssel bleibt gleich, wenn sich z. B. ein Fehlertext verändert.

Mehrere @command_check-Regeln für denselben Befehl sind ausdrücklich erlaubt:
Jede Regel erhält DIESELBE bereits abgefragte Ausgabe. Der Interpreter
führt selbst keine Netzwerkbefehle aus; die Liste tatsächlich auszuführender
Kommandos wird weiterhin vom DeviceChecker / der JSON-Konfiguration bestimmt.

Rückgabewerte einer Regel:
    False / None     -> Kein Fehler
    True             -> Nur Fehlermeldung aus @command_check (ohne Detail)
    re.Match         -> Fehlermeldung + vollständige Trefferzeile
    str              -> Fehlermeldung + konkretes Detail
    list[str]        -> Fehlermeldung + je ein konkretes Detail
    Finding          -> Fehlermeldung + Detail mit stabiler Identität
    list[Finding]    -> je eine Meldung mit stabiler Identität

Das alte @command_check("show ...") mit einer Liste fertig formulierter
Meldungen bleibt aus Kompatibilitätsgründen ebenfalls möglich.

Hinweis: Reload-Gründe und Hardware-Logs können historische Ereignisse sein;
nicht jeder gefundene Eintrag bezeichnet einen aktuell bestehenden Defekt.
"""

from collections.abc import Callable
from dataclasses import dataclass
import hashlib
import re


@dataclass(frozen=True)
class Finding:
    """Ein konkreter Fehler mit einer optionalen, stabilen Objekt-Identität."""
    detail: str
    key: str = ""


CheckResult = bool | str | Finding | list[str | Finding] | re.Match[str] | None
Check = Callable[[str], CheckResult]


@dataclass(frozen=True)
class _CheckRule:
    function: Check
    error_message: str | None
    code: str


_CHECKS: dict[str, list[_CheckRule]] = {}


def _command_name(name: str) -> str:
    """Cisco-Befehle unabhängig von Großschreibung/Leerzeichen zuordnen."""
    return " ".join(name.lower().split())


def command_check(
    command: str, error_message: str | None = None, *, code: str | None = None
) -> Callable[[Check], Check]:
    """Eine Prüffunktion einem Befehl und einer Website-Meldung zuordnen.

    Ein Befehl darf beliebig viele Prüfregeln besitzen. Das ist NUR ein
    Mapping und löst keine zusätzliche Ausführung des CLI-Befehls aus.
    """
    normalized = _command_name(command)
    if not normalized:
        raise ValueError("Der Cisco-Befehl darf nicht leer sein")
    if error_message is not None and not error_message.strip():
        raise ValueError("Die Fehlermeldung darf nicht leer sein")
    if code is not None and not re.fullmatch(r"[a-z][a-z0-9_.-]{0,70}", code):
        raise ValueError("code muss mit einem Kleinbuchstaben beginnen und darf nur a-z, 0-9, _.- enthalten")

    def register(function: Check) -> Check:
        stable_code = code or function.__name__
        if not re.fullmatch(r"[a-z][a-z0-9_.-]{0,70}", stable_code):
            raise ValueError("Ungültige Funktionsbezeichnung: Bitte code=... angeben")
        current_rules = _CHECKS.setdefault(normalized, [])
        if any(rule.code == stable_code for rule in current_rules):
            raise ValueError(f"Doppelter Check-Code für {normalized}: {stable_code}")
        current_rules.append(
            _CheckRule(function=function, error_message=error_message, code=stable_code)
        )
        return function

    return register


def _short(text: str, limit: int = 220) -> str:
    """Einzeilige Nachricht für JSON und Website-Issue."""
    return " ".join(str(text).split())[:limit]


def _rule_findings(rule: _CheckRule, output: str) -> list[Finding]:
    """Normiert ein Check-Ergebnis, ohne einzelne Fehler zusammenzufassen."""
    result = rule.function(output)
    if result is None or result is False:
        return []
    if result is True:
        if rule.error_message is None:
            raise TypeError("True braucht eine Meldung im @command_check")
        return [Finding("")]
    if isinstance(result, re.Match):
        begin = output.rfind("\n", 0, result.start()) + 1
        end = output.find("\n", result.end())
        result = output[begin:end if end >= 0 else len(output)]

    if isinstance(result, Finding):
        items = [result]
    elif isinstance(result, str):
        items = [Finding(result)]
    elif isinstance(result, list) and all(isinstance(item, (str, Finding)) for item in result):
        # Eine Liste signalisiert unabhängige Einzelfehler. Ohne expliziten Key
        # wird ihre Zeile gehasht, unabhängig von der aktuellen Trefferzahl.
        items = [Finding(item, key=item) if isinstance(item, str) else item for item in result]
    else:
        raise TypeError("Check muss bool, Regex-Match, str, Finding, Liste oder None liefern")
    return [Finding(_short(item.detail, 600), item.key) for item in items if item.detail.strip()]


def _rule_issues(command: str, rule: _CheckRule, output: str) -> list[dict[str, str]]:
    items = _rule_findings(rule, output)
    issues: list[dict[str, str]] = []
    for finding in items:
        # Eine einzelne Zeile gehört zur Regel; bei mehreren Fehlern bilden
        # stabile Bauteil-Keys (oder ersatzweise Text-Hashes) eigene Issues.
        code = rule.code
        if finding.key:
            identity = finding.key or finding.detail
            code += "." + hashlib.sha256(identity.encode("utf-8")).hexdigest()[:16]
        issues.append({
            "code": code,
            "command": command,
            "message": rule.error_message or finding.detail,
            "details": finding.detail if rule.error_message else "",
        })
    return issues


# CLI-Fehlerbehandlung gilt für alle registrierten Befehle.
_UNSUPPORTED = re.compile(
    r"(?im)^\s*(?:%\s*)?(?:invalid input detected|incomplete command|"
    r"ambiguous command|unknown command|unrecognized command|"
    r"unsupported command|command not supported|this command is not supported|"
    r"not supported on this platform|command not found|syntax error|invalid command)\b"
)
_ACCESS_DENIED = re.compile(
    r"(?im)^\s*(?:%\s*)?(?:authorization failed|permission denied|"
    r"access denied|not authorized|insufficient privileges|privilege level too low)\b"
)
_COMMAND_ERROR = re.compile(
    r"(?im)^\s*(?:%\s*)?(?:error\s*[:\-]|error\s+executing\b|"
    r"command\s+execution\s+failed\b|failed\s+to\s+execute\b|"
    r"unable\s+to\s+execute\b)"
)

# Gemeinsam genutzte Regexe.
_BAD_STATE = re.compile(
    r"\b(?:status|state)\s*[:=]\s*(?:unhealthy|not\s+healthy|warning|"
    r"critical|degraded|failed|faulty|crashed|offline|down|stopped|"
    r"dead|zombie|not\s+running)\b",
    re.I,
)
_NEGATED_FAULT = re.compile(
    r"\b(?:no|zero|0)\s+(?:[a-z][\w-]*\s+){0,3}"
    r"(?:errors?|failures?|faults?|critical\s+events?)\b|"
    r"\b(?:errors?|failures?|faults?)\s*[:=]\s*0\b|"
    r"\b(?:passed|pass|success(?:ful)?|cleared|resolved|recovered)\b",
    re.I,
)


# ---------------------------------------------------------------------------
# show version
# ---------------------------------------------------------------------------
@command_check("show version", "Auffälliger letzter Neustart", code="reload_reason")
def check_reload_reason(output: str) -> list[Finding]:
    """Unerwartete Neustarts / Crash-Ursachen melden."""
    reason_line = re.compile(
        r"(?im)^\s*(?:last\s+reload\s+reason|"
        r"system\s+returned\s+to\s+ROM\s+by|"
        r"system\s+restarted\s+by)\s*[:=]?\s*(.+)$"
    )
    abnormal = re.compile(
        r"\b(?:watchdog|crash(?:ed)?|kernel\s+panic|bus\s+error|"
        r"unexpected\s+reset|processor\s+failure|software\s+forced|"
        r"fatal|power\s+failure|uncorrectable\s+error)\b",
        re.I,
    )
    return [
        Finding(_short(match.group(1)), key="last_reload")
        for match in reason_line.finditer(output)
        if abnormal.search(match.group(1))
    ]


# ---------------------------------------------------------------------------
# show boot
# ---------------------------------------------------------------------------
@command_check("show boot", "Manueller Bootmodus ist aktiviert", code="manual_boot")
def check_manual_boot(output: str) -> re.Match[str] | None:
    return re.search(
        r"(?im)^\s*manual\s+boot\s*[:=]\s*(?:yes|true|enabled|on)\b", output
    )


@command_check(
    "show boot", "Konfigurationsregister 0x2142: Startkonfiguration wird umgangen", code="config_register_2142"
)
def check_configuration_register(output: str) -> re.Match[str] | None:
    return re.search(
        r"\b(?:configuration\s+register|config-register)\s+"
        r"(?:is\s+)?(?:set\s+to\s+)?(?:0x)?2142\b",
        output,
        re.I,
    )


@command_check("show boot", "Bootfehler erkannt", code="boot_failure")
def check_boot_failures(output: str) -> list[str]:
    boot_error = re.compile(
        r"\b(?:boot\s+(?:image|file)\s+not\s+found|"
        r"failed\s+to\s+(?:boot|load)|unable\s+to\s+(?:boot|load)|"
        r"invalid\s+boot\s+(?:image|file))\b",
        re.I,
    )
    return [_short(line) for line in output.splitlines() if boot_error.search(line)]


# ---------------------------------------------------------------------------
# show logging profile hardware-diagnostics
# ---------------------------------------------------------------------------
@command_check(
    "show logging profile hardware-diagnostics", "Hardware-Log (evtl. historisch)", code="hardware_log"
)
def check_hardware_logs(output: str) -> list[str]:
    severe_syslog = re.compile(r"%[\w-]+-[0-3]-[\w-]+\s*:", re.I)
    fault_words = re.compile(
        r"\b(?:errors?|critical|fatal|failed|failures?|faults?|faulty|"
        r"crashed|kernel\s+panic|overheating|overtemperature)\b",
        re.I,
    )
    findings = []
    for line in output.splitlines():
        line = line.strip()
        if not line:
            continue
        if severe_syslog.search(line) or (
            fault_words.search(line) and not _NEGATED_FAULT.search(line)
        ):
            findings.append(_short(line))
    return findings


# ---------------------------------------------------------------------------
# show inventory
# ---------------------------------------------------------------------------
# Ein Inventar-Eintrag besteht unter Cisco oft aus zwei oder mehr Zeilen:
# NAME/DESCR, gefolgt von PID/VID/SN und gelegentlich Status-/Fehlerzeilen.
# Daher wird jede Auffälligkeit mit dem zugehörigen Bauteil verknüpft.
_INVENTORY_NAME = re.compile(r'\bNAME\s*:\s*(?:"([^"]+)"|([^,\r\n]+))', re.I)
_INVENTORY_DESCR = re.compile(r'\bDESCR\s*:\s*(?:"([^"]+)"|([^,\r\n]+))', re.I)
_INVENTORY_PID = re.compile(r'\bPID\s*:\s*([^,\s]+)', re.I)
_INVENTORY_FAULT = re.compile(
    r"\b(?:errors?|critical|failed|faulty|crashed|unhealthy|defective)\b", re.I
)


def _inventory_value(pattern: re.Pattern[str], lines: list[str]) -> str:
    for line in lines:
        match = pattern.search(line)
        if match:
            return _short(next((part for part in match.groups() if part), ""), 90)
    return ""


@command_check("show inventory", "Hardwarefehler im Inventar", code="inventory_fault")
def check_inventory_faults(output: str) -> list[Finding]:
    """Fehler MIT Bauteilnamen, Modell und Original-Fehlerzeile melden.

    `show inventory` liefert normalerweise nur Bauteil-Informationen, keine
    Live-Gesundheitswerte. Nur ausdrücklich enthaltene Fehler melden; keine
    Defekte aus fehlenden Inventareinträgen ableiten.
    """
    groups: list[list[str]] = []
    for line in output.splitlines():
        line = line.strip()
        if not line:
            continue
        if _INVENTORY_NAME.search(line) or not groups:
            groups.append([])
        groups[-1].append(line)

    findings: list[Finding] = []
    seen_details: set[str] = set()
    for group in groups:
        name = _inventory_value(_INVENTORY_NAME, group) or "Unbekanntes Bauteil"
        description = _inventory_value(_INVENTORY_DESCR, group)
        pid = _inventory_value(_INVENTORY_PID, group)
        attributes = []
        if description and description != name:
            attributes.append(description)
        if pid and pid.upper() not in {"N/A", "NA"}:
            attributes.append(f"PID: {pid}")
        component = name + (f" ({', '.join(attributes)})" if attributes else "")

        for line in group:
            if (_BAD_STATE.search(line) or _INVENTORY_FAULT.search(line)) and not _NEGATED_FAULT.search(line):
                finding = _short(f"{component} – {line}", 320)
                if finding not in seen_details:
                    seen_details.add(finding)
                    findings.append(Finding(finding, key=name + "|" + pid + "|" + line.split(":", 1)[0].strip().lower()))
    return findings


# ---------------------------------------------------------------------------
# show platform software
# ---------------------------------------------------------------------------
@command_check("show platform software", "Problematischer Systemprozess", code="process_problem")
def check_platform_processes(output: str) -> list[str]:
    fatal = re.compile(
        r"\b(?:segmentation\s+fault|kernel\s+panic|out\s+of\s+memory|"
        r"oom\s+killer|process\s+crashed|process\s+failed)\b",
        re.I,
    )
    return [
        _short(line)
        for line in output.splitlines()
        if _BAD_STATE.search(line) or fatal.search(line)
    ]


# ---------------------------------------------------------------------------
# show platform software status control-processor
# ---------------------------------------------------------------------------
@command_check(
    "show platform software status control-processor",
    "Control-Processor meldet Fehlerzustand", code="control_processor_health",
)
def check_control_processor_health(output: str) -> list[Finding]:
    health = re.compile(
        r"^\s*(?:load\s+average|memory\s*(?:\([^)]*\))?)\s*:\s*"
        r"(?:unhealthy|not\s+healthy|warning|critical|degraded|failed)\b",
        re.I,
    )
    processor = re.compile(
        r"^\s*(?:\d+-)?(?:RP|ESP|SIP|FP|F)[\w/-]*\s*:\s*"
        r"(?:offline|down|failed|faulty|crashed|not\s+online)\b",
        re.I,
    )
    # Keine frei erfundenen Prozentgrenzwerte: Cisco bewertet die Health-Daten.
    return [
        Finding(_short(line), key=(line.split(":", 1)[0].strip().lower() if ":" in line else line.strip().lower()))
        for line in output.splitlines()
        if health.search(line) or _BAD_STATE.search(line) or processor.search(line)
    ]


# ---------------------------------------------------------------------------
# Zentrale Auswertung: Muss beim Hinzufügen neuer Checks NICHT geändert werden.
# ---------------------------------------------------------------------------
class DataInterpreter:
    # Neue @command_check-Registrierungen sind automatisch in COMMANDS enthalten.
    COMMANDS = _CHECKS.keys()
    MAX_ISSUES_PER_RULE = 150
    MAX_MESSAGE_LENGTH = 1600

    def __init__(self, data: dict[str, str]):
        self.data = data

    def interpret(self) -> dict:
        """Liefert einzelne Issues plus erfolgreich ausgewertete Befehle.

        PHP darf alte Issues eines Befehls nur dann schließen, wenn dieser
        Befehl in `evaluated_commands` steht. Ein Timeout, nicht unterstützter
        Befehl oder fehlerhafter Checker ist KEIN Nachweis für Gesundung.
        """
        if not isinstance(self.data, dict):
            return self._result([], [], [self._system_issue(
                "invalid_input", "Ungültige Cisco-Prüfdaten", "Dictionary erwartet"
            )])

        issues: list[dict[str, str]] = []
        evaluated: list[str] = []
        skipped: list[str] = []
        for raw_command, raw_output in self.data.items():
            if not isinstance(raw_command, str):
                continue
            command = _command_name(raw_command)
            checks = _CHECKS.get(command)
            if not checks:
                continue

            if not isinstance(raw_output, str):
                issues.append(self._issue("invalid_output", command,
                    "Ungültiges Ausgabeformat", "Cisco-Befehlsausgabe ist kein Text"))
                continue
            output = raw_output.replace("\r\n", "\n").replace("\r", "\n").strip()
            if not output:
                skipped.append(f"{command} (keine Ausgabe)")
                continue
            match = _UNSUPPORTED.search(output)
            if match:
                line = output[match.start():].splitlines()[0]
                issues.append(self._issue("unsupported_command", command,
                    "CLI-Befehl wird nicht unterstützt", _short(line, 600)))
                # Dieser Befehl wurde nicht vollständig geprüft: Bestehende
                # Hardwarefehler bleiben deshalb weiterhin offen.
                continue
            if _ACCESS_DENIED.search(output):
                issues.append(self._issue("access_denied", command,
                    "Fehlende Berechtigung zur Ausführung", _short(output, 600)))
                continue
            if command != "show logging profile hardware-diagnostics":
                match = _COMMAND_ERROR.search(output)
                if match:
                    line = output[match.start():].splitlines()[0]
                    issues.append(self._issue("cli_error", command,
                        "CLI-Befehl fehlgeschlagen", _short(line, 600)))
                    continue

            command_complete = True
            for rule in checks:
                try:
                    rule_issues = _rule_issues(command, rule, output)
                    if len(rule_issues) > self.MAX_ISSUES_PER_RULE:
                        command_complete = False
                        issues.append(self._issue("too_many_findings", command,
                            "Zu viele Diagnosemeldungen", f"Prüfung {rule.code} meldet "
                            f"{len(rule_issues)} Auffälligkeiten; bitte Ausgabe genauer eingrenzen"))
                        issues.extend(rule_issues[:self.MAX_ISSUES_PER_RULE])
                    else:
                        issues.extend(rule_issues)
                except Exception as exc:
                    command_complete = False
                    issues.append(self._issue("rule_error." + rule.code, command,
                        "Auswertung fehlgeschlagen", f"{rule.function.__name__}: {_short(str(exc), 500)}"))
            if command_complete:
                evaluated.append(command)

        # Identische Treffer dürfen bei der Speicherung keinen UNIQUE-Konflikt
        # auslösen. Hier entscheidet nur die stabile (command, code)-Identität.
        deduplicated: dict[tuple[str, str], dict[str, str]] = {}
        for issue in issues:
            deduplicated[(issue["command"], issue["code"])] = issue
        issues = list(deduplicated.values())
        if not issues and not evaluated:
            suffix = " (Befehle ohne Ausgabe)" if skipped else ""
            issues.append(self._system_issue("no_diagnostic_output",
                "Keine auswertbaren Cisco-Diagnoseausgaben", suffix.strip()))
        return self._result(evaluated, skipped, issues)

    @staticmethod
    def _issue(code: str, command: str, message: str, details: str) -> dict[str, str]:
        return {"code": code, "command": command, "message": message,
                "details": _short(details, 600)}

    @classmethod
    def _system_issue(cls, code: str, message: str, details: str) -> dict[str, str]:
        return cls._issue(code, "_system", message, details)

    @classmethod
    def _result(cls, evaluated: list[str], skipped: list[str], issues: list[dict[str, str]]) -> dict:
        if issues:
            summary = f"{len(issues)} Auffälligkeit(en) erkannt"
        else:
            count = len(evaluated)
            word = "Befehl" if count == 1 else "Befehle"
            summary = f"Keine Auffälligkeiten erkannt ({count} Cisco-{word} ausgewertet)"
        if skipped:
            summary += "; nicht ausgewertet: " + ", ".join(skipped)
        return {"status": "error" if issues else "ok", "output": summary[:cls.MAX_MESSAGE_LENGTH],
                "issues": issues, "evaluated_commands": evaluated}
