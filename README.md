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
