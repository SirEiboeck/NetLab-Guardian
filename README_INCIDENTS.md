# NetLab Guardian – Checks und Incidents

Diese Version basiert auf der mitgelieferten `netlab.zip`. Der **DataInterpreter bleibt dekoratorbasiert**: Die zentrale Klasse muss zum Hinzufügen eines Checks nicht verändert werden. Die Python-Ausgabe wird durch `app/Checks/IncidentResults.php` aufbereitet, `app/Controllers/dashboard.php` steuert den Aufruf und `app/Database/db_internal_api.php` speichert die Incidents in SQLite.

## Neuen Check hinzufügen

In `python_scripts/data_interpreter.py` einen Block ergänzen:

```python
@command_check("show environment", "Lüfter meldet einen Fehler", code="fan_failed")
def check_fan(output: str) -> list[Finding]:
    findings = []
    for line in output.splitlines():
        if "failed" in line.lower() and "fan" in line.lower():
            # Der Key ist die Identität des Bauteils. Nicht den
            # veränderlichen Status ('failed'/'degraded') als Key verwenden.
            fan_name = line.split(":", 1)[0].strip()
            findings.append(Finding(detail=line.strip(), key=fan_name))
    return findings
```

Ein Check liefert `None`/`False` (gesund), `True` (Fehler ohne Detail), `re.Match`, `str`, `list[str]`, `Finding` oder `list[Finding]`. Der Dekorator enthält die nutzerfreundliche Fehlermeldung. **`code` muss pro Befehl dauerhaft stabil und eindeutig bleiben.** Bei mehreren Fehlern derselben Regel `Finding(key="stabile-identität", detail="aktueller Zustand")` verwenden. Ändert sich nur der Detailtext, bleibt der Incident derselbe. Mehrere Checks pro CLI-Befehl sind ausdrücklich möglich.

Wichtig: Die Registrierung startet **nicht** selbst einen CLI-Befehl. `show environment` muss außerdem in der Datenbanktabelle `command` bzw. der Rack-Check-JSON unter `defaults.commands` / `devices[IP].commands` stehen. Der `DeviceChecker` führt den Befehl einmal aus; alle registrierten Prüfregeln bekommen dieselbe Ausgabe.

## Format zwischen Python und PHP

Der Interpreter liefert für jedes Gerät sinngemäß:

```json
{
  "status": "error",
  "output": "1 Auffälligkeit(en) erkannt",
  "issues": [
    {
      "code": "fan_failed.c22cf581056f5cad",
      "command": "show environment",
      "message": "Lüfter meldet einen Fehler",
      "details": "FAN1: failed"
    }
  ],
  "evaluated_commands": ["show environment"]
}
```

Die Zahl hinter dem Regel-Code ist ein Hash des stabilen `Finding.key`, kein zufälliger Wert. `evaluated_commands` enthält nur Befehle, bei denen **alle** zugehörigen Regeln vollständig durchgelaufen sind. Ein leerer/ungültiger oder nicht unterstützter CLI-Befehl darf bestehende Diagnosen nicht automatisch schließen.

## Incident-Lebenszyklus

1. PHP erzeugt aus `command` und `code` einen stabilen `source_key` (`python|...|...`). Pro Gerät und `source_key` darf **höchstens ein offener Incident** existieren; ein partieller SQLite-Unique-Index schützt auch bei konkurrierenden Requests.
2. Wenn der Fehler erneut auftritt, bleiben **ID und `detected_at`** gleich. Beschreibung und `last_seen_at` werden aktualisiert.
3. Fehlt der Fehler nach **vollständiger, erfolgreicher Auswertung seines CLI-Befehls**, wird `resolved_at` gesetzt. Der alte Datensatz bleibt in der Historie; taucht der Fehler später wieder auf, entsteht ein neuer offener Incident.
4. Timeouts, fehlende Python-Antworten, Authentifizierungsfehler und nicht unterstützte CLI-Befehle sind keine Entwarnung für bisherige Hardwarefehler. Manuelle Incidents (ohne `python|`-Quelle) werden nie automatisch aufgelöst.
5. Die Synchronisierung pro Gerät erfolgt als **Datenbanktransaktion**. Bestehende SQLite-Dateien werden durch die DB-Klasse automatisch um `last_seen_at` ergänzt. Eine Sicherung vor dem Rollout ist trotzdem empfehlenswert.

## Anzeige und Betrieb

Auf der Startseite unter **Incidents** gibt es Filter für **Offen / Gelöst / Alle**. Die Tabelle zeigt Gerät/IP, Check, Meldung, Erkennungsdatum, zuletzt gesehen und Lösungsdatum. Jeder Filter zeigt höchstens 200 Einträge (mit Anzahl aller Einträge). Datum/Uhrzeit werden als Europe/Vienna dargestellt.

Für die Webansicht benötigt PHP `pdo_sqlite`, für echte Gerätechecks braucht Python `netmiko`. Nach einem Update des Python-Teils muss die Website mit der richtigen Python-Umgebung starten (`python_scripts/.venv/bin/python` oder `NETLAB_PYTHON`).

## Fehlerbehebung beim Gerätecheck

Falls Python wie zuvor `too many values to unpack (expected 2, got 4)` meldet, zeigen aktuelle Rückgaben jetzt den betroffenen Schritt an: `Geräteverbindung fehlgeschlagen` oder `Cisco-Befehl konnte nicht ausgeführt werden` mit der ursprünglichen Fehlermeldung. Der genaue Python-Traceback wird **nur im PHP-/Webserver-Fehlerlog** protokolliert, mit maskierten Zugangspasswörtern. Prüfe darin den Eintrag `NetLab-Check Python-Diagnose` und den Python-Schritt `Verbindungsaufbau ...` oder `Befehl show ...`, um bei einem Netmiko-/Geräteproblem die genaue Ursache zu sehen.

Ein einzelner fehlgeschlagener CLI-Befehl stoppt die anderen Checks nicht. Befehle mit Fehlern landen nicht in `evaluated_commands`: Bestehende Hardware-Incidents bleiben für diese Befehle offen, bis ein späterer Check wirklich erfolgreich durchgeführt wurde. Die Portangabe aus der JSON-Konfiguration wird nun auch an Netmiko übergeben.

**Wichtig:** Ohne Traceback lässt sich nicht sicher sagen, wo das ursprüngliche Unpacking-Problem entstanden ist. Diese Version verhindert, dass es als unspezifischer Sammelfehler angezeigt wird, und liefert die fehlende Diagnose, um den eigentlichen Ursprung eindeutig zu lokalisieren.
