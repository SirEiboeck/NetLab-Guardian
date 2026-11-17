# NetLab Guardian

Lokales Netzwerk-Inventar mit Rack-Checks und Incident-Verlauf.

## Installation

Das Projekt liegt z. B. unter `/var/www/NetLab_Guardian`. Webserver-DocumentRoot ist `public/`.

```bash
cd /var/www/NetLab_Guardian
sudo bash install.sh --app-user user --web-user www-data --admin admin
```

Der Installer prüft SQLite-/Verzeichnisrechte, erstellt die Python-`.venv`, installiert die Pakete und richtet bei Bedarf den Administrator ein. Ein vorhandenes Admin-Passwort bleibt ohne `--reset-admin` unverändert.

```bash
sudo bash install.sh --check --app-user user --web-user www-data
```

Weitere Informationen: [INSTALLATION.md](INSTALLATION.md).

## Projektstruktur

| Verzeichnis / Datei | Zweck |
|---|---|
| `app/Checks/` | Netzwerkchecks, Konfiguration und Incident-Verarbeitung |
| `app/Controllers/` | Formulare und Anwendungslogik |
| `app/Database/db_internal_api.php` | SQLite-Klasse |
| `app/Support/` | Pfade, Anmeldung, Hilfsfunktionen |
| `app/Views/` | gemeinsame Layouts und Seitenansichten |
| `public/` | Web-Einstiegspunkte, CSS und JavaScript |
| `public/assets/css/ui-unified.css` | einheitliches Erscheinungsbild |
| `public/assets/css/visual-interactions.css` | Donutdiagramme und stabile Gerätetabelle |
| `public/assets/js/interface.js` | Bearbeitungsmodus und Aktionen ohne Seitenwechsel |
| `python_scripts/` | DataInterpreter und Python-Checks |
| `bin/user.php` | CLI-Notfallwerkzeug zur Benutzer-/Passwortverwaltung |
| `storage/` | SQLite-Datenbank und Rack-Sperrdatei |
| `install.sh` | Installer |

Die Website verwaltet globale Netzwerk-Credentials. Sie gelten für alle Geräte. Bestehende Einträge in der alten Zuordnungstabelle `device_credential` werden nicht gelöscht, aber nicht mehr für Checks verwendet. Die Passwörter der Geräte liegen weiterhin für die automatisierte Anmeldung in SQLite vor, werden jedoch nicht im Webformular angezeigt. Die Website-Benutzerpasswörter sind davon unabhängig und gehasht.

## Bedienung

- **Übersicht (`index.php`):** Zwei interaktive Donutdiagramme (Gerätestatus und Incidents), dauerhaft sichtbare Racks.
- **Geräte & Incidents (`details.php`):** Suchbare Geräte-Tabelle und Incident-Historie mit Statusfiltern.
- **Gerät (`device.php?id=...`):** Werte standardmäßig nur lesen, über „Bearbeiten“ in derselben Tabelle ändern und gemeinsam speichern. Die Tabelle bleibt dabei gleich groß.
- **Gerät hinzufügen (`device.php`):** Noch nicht existierendes Gerät in derselben Tabelle erfassen; Bearbeitungsmodus ist hier direkt aktiv.
- **Manuelle Incidents:** Erst-/Zuletzt-Zeitstempel, manuell lösen oder endgültig löschen; automatische Check-Incidents bleiben geschützt.
- **Racks:** Meldungen mit × ohne Seitenwechsel schließen, Prüfaktionen aktualisieren die Ansicht ohne Ankersprung (JavaScript, mit PHP-Fallback). Mindestens zehn HE; bei höher belegten Positionen wächst die Ansicht bei allen sichtbaren Racks gleichmäßig bis zu 30 HE. JSON-Einstellungen bleiben optional aufklappbar.
- **Zugangsdaten:** Benutzername, Passwort und Bearbeiten/Löschen – keine Gerätezuordnung.

Die DataInterpreter-Checks können weiterhin über `@command_check(...)` ergänzt werden. Siehe [README_INCIDENTS.md](README_INCIDENTS.md).

## Automatischer Incident-Bericht per E-Mail (SMTP)

Ein Backend-Service verschickt **einmal täglich zur konfigurierten Uhrzeit** alle offenen
Incidents nach Geräten gruppiert. Absender, Empfänger und SMTP-Server stehen in
`config/incident_mail.php`; das **SMTP-/App-Passwort wird separat geschützt gespeichert**.
Dafür brauchst du keine Microsoft-Entra-IDs. `install.sh` richtet systemd-Service und
Timer mit `OnCalendar` für einen Start pro Tag automatisch ein; ohne gültige Konfiguration und hinterlegtes Passwort bleibt
der Timer deaktiviert. **Hinweis:** Der Mailanbieter
muss SMTP-Passwort-Login zulassen; insbesondere bei Microsoft-365-Schulkonten ist
diese Funktion oft gesperrt. Mit `sudo bash install.sh --test-mail` lässt sich direkt über die Kommandozeile
der vollständige Incident-Bericht aus dem gespeicherten Datenbankstand verschicken
(ohne erneuten Gerätecheck und ohne Einfluss auf den Timer).
Siehe [README_MAIL.md](README_MAIL.md).

## Aktualisierung

Vor dem Austausch `storage/inventory.sqlite` sichern. Dateien gemäß [DATEIPFADE.md](DATEIPFADE.md) übernehmen. Die **alte Datei `setup.php` im Projektstamm löschen**; ihre Aufgabe übernimmt bei der Installation `install.sh`, zur manuellen Passwortänderung `bin/user.php`.

Keine Tests oder Python-`.venv` werden mitgeliefert.

## Änderungen an bestehenden Installationen

Die frühere `admin.php` wird nicht mehr benötigt. Nach dem Update diese drei Altdateien entfernen, damit keine veraltete Verwaltungsoberfläche erreichbar ist:

- `public/admin.php`
- `app/Controllers/admin.php`
- `app/Views/pages/admin.php`

Neue Einstiegsseite für Detailtabellen: `public/details.php`. Änderungen an Geräten nur als Administrator. Incidents aus Python werden weiterhin allein über die Check-Synchronisierung aktualisiert, manuell angelegte Incidents bleiben davon unabhängig.

Formularaktionen auf den Verwaltungsseiten werden bei aktiviertem JavaScript ohne vollständiges Neuladen verarbeitet. Ohne JavaScript funktionieren die bisherigen PHP-Formulare weiter. Es wird keine zusätzliche Bibliothek benötigt.

## Fix: HTTP 404 bei AJAX-Aktionen und kompakte Diagramme

Bei Formularaktionen im Dashboard wurde vorher nach dem POST automatisch einem Redirect auf `index.php` gefolgt. Je nach Webserver-Konfiguration kann das zu HTTP 404 führen, selbst wenn der POST bereits verarbeitet wurde. Der aktuelle JavaScript-/PHP-Ablauf verwendet die erreichbare Seitenadresse für den POST und einen separaten AJAX-Redirect-Header (`X-NetLab-Redirect`). Dadurch lässt sich der Inhalt ohne Seiten- und Scrollsprung aktualisieren; eine Aktion wird bei einem 404 nicht automatisch erneut gesendet. Ohne JavaScript bleiben reguläre Formulare erhalten. Die SVG-Donuts sind als kompakte Statuskarten gestaltet.

**Dateipfade für das Update:** siehe `DATEIPFADE.md`.

### Automatische Gerätedaten und täglicher HTML-Mailbericht

Beim Rack-Check liest Python zusätzlich `show version` und `show inventory`
über die vorhandene Netmiko-Verbindung und aktualisiert, soweit erkennbar,
Hostname, Firmware, Modell und Seriennummer im Inventar. Auf der Geräte- und Incident-Seite werden die neuen Angaben sowie direkte Aktionsbuttons angezeigt.
Das bisherige Incident-System bleibt erhalten. Der SMTP-Mailservice versendet
jetzt HTML-Berichte mit Outlook-kompatiblen Donut-PNGs und einer Incident-Tabelle.

Die Mailfunktion kann direkt im interaktiven `sudo bash install.sh` eingerichtet
werden; später erneut mit `sudo bash install.sh --setup-mail`. Der manuelle
Versand desselben Berichts erfolgt über `sudo bash install.sh --test-mail`.
Details: `README_MAIL.md` und `README_INCIDENTS.md`.

### Neues Inventar und direkte Incident-Verwaltung

Der Python-Check liest zusätzlich `show license udi` und `show platform`,
wenn Modell, Seriennummer oder Geräteklasse nicht anhand von `show version`
und `show inventory` erkannt werden können. Die Geräteklasse unterscheidet
**Switch / Router / Sonstiges / Unbekannt**. Die Felder Konsolenport und
Inventarnummer sind aus SQLite und Geräteansicht entfernt. Beim ersten Zugriff
auf bestehende Datenbanken migriert die DB-Klasse das Schema (SQLite 3.35+);
davor eine Sicherung der Datenbank erstellen.

Auf `details.php` lassen sich offene Incidents jetzt direkt mit **Lösen**
abschließen. Manuelle Incidents können dort zusätzlich gelöscht werden;
automatische Incidents bleiben in der Check-Historie.
