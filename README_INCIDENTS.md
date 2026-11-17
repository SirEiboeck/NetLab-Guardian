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

Auf der Detailseite `details.php` unter **Incidents** gibt es Filter für **Offen / Gelöst / Alle**. Die Tabelle zeigt Gerät/IP, Check, Meldung, Erkennungsdatum, zuletzt gesehen und Lösungsdatum. Jeder Filter zeigt höchstens 200 Einträge (mit Anzahl aller Einträge). Die Datumswerte werden in der Oberfläche formatiert. Manuelle Incidents erhalten beim Anlegen explizit `detected_at` und `last_seen_at`. Administratoren können offene Incidents direkt in der Tabelle auf `details.php` mit **Lösen** schließen. Für manuell erstellte Incidents gibt es dort zusätzlich **Löschen** (mit Sicherheitsabfrage); automatische Check-Incidents bleiben als Historie erhalten. Die Aktionen verwenden POST und CSRF-Schutz, ohne Umweg über die Geräteansicht.

Für die Webansicht benötigt PHP `pdo_sqlite`, für echte Gerätechecks braucht Python `netmiko`. Nach einem Update des Python-Teils muss die Website mit der richtigen Python-Umgebung starten (`python_scripts/.venv/bin/python` oder `NETLAB_PYTHON`).

## Fehlerbehebung beim Gerätecheck

Falls Python wie zuvor `too many values to unpack (expected 2, got 4)` meldet, zeigen aktuelle Rückgaben jetzt den betroffenen Schritt an: `Geräteverbindung fehlgeschlagen` oder `Cisco-Befehl konnte nicht ausgeführt werden` mit der ursprünglichen Fehlermeldung. Der genaue Python-Traceback wird **nur im PHP-/Webserver-Fehlerlog** protokolliert, mit maskierten Zugangspasswörtern. Prüfe darin den Eintrag `NetLab-Check Python-Diagnose` und den Python-Schritt `Verbindungsaufbau ...` oder `Befehl show ...`, um bei einem Netmiko-/Geräteproblem die genaue Ursache zu sehen.

Ein einzelner fehlgeschlagener CLI-Befehl stoppt die anderen Checks nicht. Befehle mit Fehlern landen nicht in `evaluated_commands`: Bestehende Hardware-Incidents bleiben für diese Befehle offen, bis ein späterer Check wirklich erfolgreich durchgeführt wurde. Die Portangabe aus der JSON-Konfiguration wird nun auch an Netmiko übergeben.

**Wichtig:** Ohne Traceback lässt sich nicht sicher sagen, wo das ursprüngliche Unpacking-Problem entstanden ist. Diese Version verhindert, dass es als unspezifischer Sammelfehler angezeigt wird, und liefert die fehlende Diagnose, um den eigentlichen Ursprung eindeutig zu lokalisieren.

## Automatische Geräteinventarisierung beim Python-Check

`python_scripts/device_metadata.py` liest zusätzlich zu den vorhandenen
Diagnoseprüfungen Geräteinformationen aus Cisco IOS/IOS-XE über die **bereits
bestehende authentifizierte Netmiko-Verbindung** aus:

- `show version`: Firmware/IOS-Version, Hostname (alternativ CLI-Prompt),
  Hardware-Modell, Geräte-Seriennummer, Uptime, Betriebssystemfamilie,
  System-Image.
- `show inventory`: Chassis-Modell (`PID`) und Chassis-Seriennummer (`SN`),
  mit eigener Priorisierung der Hauptbaugruppe gegenüber Netzteilen, SFPs, Modulen usw.
- `show license udi`: Fallback für fehlende Chassis-Seriennummern und Produkt-IDs.
- `show platform`: Fallback bei unklarem Modell beziehungsweise Gerätetyp.

Diese Inventarbefehle werden bei verbundenen Geräten bei Bedarf zusätzlich
abgerufen. Sie beeinflussen keine bestehenden Check-Regeln oder den Status
`evaluated_commands`: Ein nicht unterstützter Inventarbefehl löst keine
vorhandenen Incidents. Bereits von Diagnose-Checks gelesene Ausgaben werden
wiederverwendet, um unnötige Zugriffe zu vermeiden.

Das PHP-Backend speichert die erkannten Werte automatisch in der vorhandenen
`device`-Tabelle. Die bisherigen Spalten `model`, `serial_number`, `firmware`,
`hostname` und `device_type` werden weiterverwendet; ergänzende Felder
`discovered_hostname`, `os_family`, `uptime`, `software_image` und
`last_discovered_at` werden bei bestehenden SQLite-Datenbanken automatisch
migriert. Leere/ungültige Werte überschreiben nichts; manuell vergebene
Hostnamen bleiben erhalten, während automatisch erzeugte `Scan-...`-Namen
durch echte Cisco-Hostnamen ersetzt werden. Die tatsächlich gemeldeten
Hostnamen stehen zusätzlich in `discovered_hostname`. Der Geräteklasse
`device_type` wird bei eindeutiger Erkennung **Switch**, **Router** oder
**Sonstiges** zugewiesen; ist kein Nachweis möglich, bleibt **Unbekannt**.

**Inventarbereinigung:** Die nicht automatisch ermittelbaren Felder
`console_port` und `inventory_number` sind aus `schema.sql` und der
Geräteansicht entfernt. Der erste Datenbankzugriff nach dem Update entfernt
sie auch aus bestehenden SQLite-Datenbanken mit `ALTER TABLE DROP COLUMN`
(SQLite 3.35 oder neuer). Vor dem Update die SQLite-Datenbank sichern,
falls dort noch eigene Werte in diesen Feldern hinterlegt waren.

Die Erkennung setzt eine erfolgreiche Cisco-Anmeldung und passende CLI-
Berechtigungen voraus. Die Parser decken verbreitete IOS/IOS-XE-Chassis-
und Seriennummern-Formate ab, aber nicht jedes Modell oder jede Cisco-
Software-Version: eine hundertprozentige Erkennung ist ohne unterstützte
Geräteantwort technisch nicht möglich. Andere Hersteller und Geräte ohne Cisco-kompatible
`show`-Ausgaben liefern ggf. keine Metadaten; es werden keine Angaben geraten.
Keine Geheimnisse oder kompletten Running-Configs werden ins Inventar geschrieben.


## Automatischer Check vor dem täglichen Mailversand

Der systemd-Mailtimer ruft den bestehenden Python-Check für alle aktiven,
nicht gesperrten Geräte mit gültiger IP auf. Der gemeinsame Rack-Check-Lock
verhindert parallele Bearbeitung. Die Ergebnisse werden wie bei der manuellen
Prüfung in `device` und `issue` gespeichert, **erst danach** erstellt
`bin/incident_mail.php` den HTML-/Textbericht. Ein technischer Gesamtfehler
verhindert den Mailversand, während einzelne Geräteausfälle als Incidents
im Bericht erscheinen. Der manuelle CLI-Befehl `sudo bash install.sh --test-mail`
verwendet dagegen ausschließlich den bestehenden DB-Stand und startet
**keinen** Gerätecheck. Es gibt keinen Discovery-Scan.
