"""Hardware-Inventarisierung aus Cisco CLI-Daten ohne erfundene Platzhalter.

Unterstützte Primärquellen: show version / show inventory.
Gezielte Fallbacks: show license udi / show platform.
Produkt-IDs von Zubehör (SFP, Netzteil, Lüfter) sind keine Chassis-IDs.
"""

import re

_ANSI = re.compile(r"\x1b\[[0-9;]*[A-Za-z]")
_INVALID = {"", "N/A", "NA", "NONE", "NULL", "UNKNOWN", "UNAVAILABLE", "NOT AVAILABLE", "-", "0"}


def _clean(value: str, limit: int = 120) -> str:
    value = _ANSI.sub("", str(value))
    value = "".join(char for char in value if char.isprintable() and char != "\x7f")
    value = " ".join(value.split())
    return value[:limit].strip().strip(" \"',;")


def _valid(value: str, limit: int = 120) -> str:
    value = _clean(value, limit)
    return value if value.upper() not in _INVALID else ""


def _first(patterns: list[str], output: str, limit: int = 120) -> str:
    for pattern in patterns:
        match = re.search(pattern, output, re.I | re.M)
        if match:
            for group in match.groups():
                if group is not None:
                    found = _valid(group, limit)
                    if found:
                        return found
    return ""


def _prompt_hostname(prompt: str) -> str:
    match = re.fullmatch(
        r"\s*([A-Za-z0-9][A-Za-z0-9_.-]{0,79})(?:\([^\r\n)]{1,60}\))?[#>]\s*",
        prompt,
    )
    return match.group(1) if match else ""


def _inventory_main_unit(output: str) -> tuple[str, str, str]:
    """PID, SN und Beschreibung des Hauptgeräts (nicht eines Einschubmoduls)."""
    blocks = re.split(r"(?=^\s*NAME\s*:\s*)", output, flags=re.I | re.M)
    best = (-10000, "", "", "")
    for block in blocks:
        if not re.match(r"^\s*NAME\s*:", block, re.I):
            continue
        name = _first([r'^\s*NAME\s*:\s*"([^"]+)"', r'^\s*NAME\s*:\s*([^,\r\n]+)'], block)
        description = _first([r'\bDESCR\s*:\s*"([^"]+)"', r'\bDESCR\s*:\s*([^\r\n]+)'], block, 200)
        pid = _first([r'\bPID\s*:\s*([^,\s\r\n]+)', r'\bProduct\s+ID\s*:\s*([^,\s\r\n]+)'], block)
        sn = _first([r'\b(?:SN|Serial\s+Number)\s*:\s*([^,\s\r\n]+)'], block)
        if not pid and not sn:
            continue
        label = f"{name} {description}"
        rank = 0
        if re.search(r"\b(chassis|system|switch\s*\d*|router|base unit|fixed chassis|stack)\b", label, re.I):
            rank += 90
        if name.strip().lower() in ("1", "0", "switch 1", "switch 0"):
            rank += 75
        if re.search(r"\b(fan|power|psu|sfp|transceiver|module|line card|interface|uplink|port adapter|supply|gbic|stackwise|cable)\b", label, re.I):
            rank -= 170
        if _device_class(pid, ""):
            rank += 35
        if sn:
            rank += 7
        # Ein ausschließlich erkanntes Netzteil/SFP darf nicht als Gerät gespeichert werden.
        if rank < 0:
            continue
        if rank > best[0]:
            best = (rank, pid, sn, description)
    return best[1], best[2], best[3]


def _license_udi(output: str) -> tuple[str, str]:
    """Cisco UDI-Formate aus IOS/IOS-XE (Modell/Seriennummer)."""
    pid = _first([r'\bPID\s*:\s*([A-Za-z0-9][A-Za-z0-9._/-]+)'], output)
    sn = _first([r'\bSN\s*:\s*([A-Za-z0-9][A-Za-z0-9._/-]+)'], output)
    if pid or sn:
        return pid, sn
    # CLI-Tabelle: Device#  PID  SN  UDI / *0  C9300-48P  FOC...  ...
    for line in output.splitlines():
        match = re.match(r'^\s*(?:\*?\d+|Device\s+\d+)\s+([\w./-]{4,})\s+([\w.-]{5,})\b', line, re.I)
        if match:
            candidate_pid, candidate_sn = match.groups()
            if candidate_pid.lower() not in ("device", "pid", "model"):
                return _valid(candidate_pid), _valid(candidate_sn)
    return "", ""


def _device_class(model: str, evidence: str) -> str:
    model = model.upper().strip()
    # Nicht jedes C9xxx ist ein Switch: C9800 bezeichnet WLAN-Controller.
    if re.match(r'^(?:C9800|AIR-|WLC|ASA|FPR|FIREPOWER|WSA|ESA)', model):
        return "Sonstiges"
    if re.match(r'^(?:WS-C|C(?:9200|9300|9400|9500|9600|1000|2960|3560|3650|3750|3850|4500|6500)|IE[-0-9]|CBS|SG\d|SF\d|N[3579]K[-\d]|NEXUS)', model):
        return "Switch"
    if re.match(r'^(?:ISR|ASR|NCS|CRS|IR[-\d]|C(?:8000|8200|8300|8500|1100|1111|1121)|(?:8[0-9]{2}|18[0-9]{2}|19[0-9]{2}|28[0-9]{2}|29[0-9]{2}|39[0-9]{2}|43[0-9]{2}|44[0-9]{2}|72[0-9]{2}))', model):
        return "Router"
    if re.search(r'\b(wireless lan controller|access point|firepower|adaptive security appliance)\b', evidence, re.I):
        return "Sonstiges"
    if re.search(r'\b(?:catalyst|nexus)\b[^\r\n]{0,80}\b(?:switch|switching)\b|\bSwitch\s+Ports\s+Model\b|\b(?:layer\s*[23]|L[23])\s+switch\b', evidence, re.I):
        return "Switch"
    if re.search(r'\b(?:integrated services router|aggregation services router|edge router|router chassis)\b', evidence, re.I):
        return "Router"
    return ""


def extract_device_metadata(outputs: dict[str, str], prompt: str = "") -> dict[str, str]:
    """Nur verifizierbare Attribute melden. Bei gesperrten Befehlen bleiben Felder leer."""
    version = _ANSI.sub("", outputs.get("show version", "") or "")
    inventory = _ANSI.sub("", outputs.get("show inventory", "") or "")
    license_output = _ANSI.sub("", outputs.get("show license udi", "") or "")
    platform = _ANSI.sub("", outputs.get("show platform", "") or "")
    result: dict[str, str] = {}

    hostname = _first([
        r'^([A-Za-z0-9][A-Za-z0-9_.-]{0,79})\s+uptime\s+is\b',
        r'^([A-Za-z0-9][A-Za-z0-9_.-]{0,79})\s+up\s+for\b',
        r'^\s*hostname\s+([A-Za-z0-9][A-Za-z0-9_.-]{0,79})\s*$',
        r'^\s*Device\s+name\s*:\s*([A-Za-z0-9_.-]+)',
    ], version, 80) or _prompt_hostname(prompt)
    if hostname:
        result['hostname'] = hostname

    firmware = _first([
        r'Cisco\s+IOS(?:\s*[- ]\s*XE)?\s+Software[^\r\n]*?\bVersion\s+([^,\s\r\n]+)',
        r'Cisco\s+IOS\s*[- ]\s*XE\s+Software\s*,?\s*Version\s+([^,\s\r\n]+)',
        r'^\s*Cisco\s+IOS-XE\s+software,\s*Version\s+([^,\s\r\n]+)',
        r'^\s*NXOS:\s*version\s+([^\s\r\n]+)',
        r'^\s*system:\s+version\s+([^\s\r\n]+)',
        r'^\s*(?:Software|Firmware)\s+Version\s*:\s*([^\s\r\n]+)',
    ], version)
    if firmware:
        result['firmware'] = firmware

    pid, serial, description = _inventory_main_unit(inventory)
    udi_pid, udi_serial = _license_udi(license_output)
    model = pid or _first([
        r'^\s*(?:Model\s+[Nn]umber|Model\s+[Nn]ame|Chassis\s+[Tt]ype|Platform|Product\s+ID)\s*:\s*([A-Za-z0-9][\w./-]+)',
        r'^\s*cisco\s+([A-Za-z0-9][\w./-]+)\s+\([^\r\n]+\)\s+processor\b',
        r'^\s*cisco\s+([A-Za-z0-9][\w./-]+)\s+processor\b',
        r'^\s*\*?\s*\d+\s+\d+\s+([A-Za-z0-9][\w./-]+)\s+\S+\s+\S+',
    ], version) or udi_pid or _first([
        r'^\s*(?:Model\s+[Nn]umber|Chassis\s+[Tt]ype|Platform)\s*:\s*([A-Za-z0-9][\w./-]+)',
    ], platform)
    # IOS-Hardware-Ausgabe enthält nicht immer explizit "Model".
    if not model:
        model = _first([r'\b(?:Catalyst|Nexus)\s+([0-9]{3,5}[A-Za-z0-9-]*)\s+(?:Series|Switch)'], version)
    if model:
        result['model'] = model

    serial = serial or _first([
        r'^\s*(?:Chassis|System|Mainboard|Baseboard)\s+[Ss]erial\s+(?:[Nn]umber|[Nn]o\.?)\s*:\s*([^\s\r\n,]+)',
        r'^\s*Processor\s+board\s+ID\s+([^\s\r\n,]+)',
        r'^\s*Motherboard\s+[Ss]erial\s+[Nn]umber\s*:\s*([^\s\r\n,]+)',
        r'^\s*Serial\s+[Nn]umber\s*:\s*([^\s\r\n,]+)',
    ], version) or udi_serial
    if serial and len(serial) >= 4:
        result['serial_number'] = serial

    uptime = _first([r'^[^\r\n]+?\b(?:uptime\s+is|up\s+for)\s+([^\r\n]+)'], version, 180)
    if uptime:
        result['uptime'] = uptime
    image = _first([
        r'^\s*System\s+image\s+file\s+is\s+["\']?([^"\'\r\n]+)',
        r'^\s*System\s+image\s*:\s*([^\r\n]+)',
    ], version, 250)
    if image:
        result['software_image'] = image

    if re.search(r'Cisco\s+IOS(?:\s*[- ]\s*XE)|IOS-XE', version, re.I):
        result['os_family'] = ('Cisco IOS XE' if re.search(r'IOS\s*[- ]\s*XE|IOS-XE', version, re.I)
                               else 'Cisco IOS')
    elif re.search(r'NX-OS|NXOS', version, re.I):
        result['os_family'] = 'Cisco NX-OS'

    category = _device_class(model, f'{description}\n{version}\n{platform}')
    if category:
        result['device_type'] = category
    return result
