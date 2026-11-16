# Dateipfade und Installation

Alle Pfade sind **relativ zum Hauptordner `NetLab-Guardian/`**, also dem Verzeichnis, in dem `bootstrap.php`, `schema.sql` und `setup.php` liegen. Die ZIP-Dateien enthalten die Dateien bereits in der richtigen Ordnerstruktur. Nicht jede Datei einzeln in `public/` hochladen.

## NEU: Installer – genaue Zielpfade

Projektstamm auf deinem Server: `/var/www/NetLab_Guardian/` (den Pfad bei abweichendem Deployment entsprechend anpassen).

| Datei in der ZIP | Ziel auf deinem Server | Aufgabe |
| --- | --- | --- |
| `.gitignore` | `/var/www/NetLab_Guardian/.gitignore` | Schließt DB, Backups und `.venv` von Git aus |
| `install.sh` | `/var/www/NetLab_Guardian/install.sh` | Installer, Rechteprüfung, Systempakete, `.venv`, Admin-Setup |
| `python_scripts/requirements.txt` | `/var/www/NetLab_Guardian/python_scripts/requirements.txt` | Benötigtes Python-Paket Netmiko |
| `setup.php` | `/var/www/NetLab_Guardian/setup.php` | Admin-Benutzer interaktiv anlegen oder Passwort ändern |
| `bootstrap.php` | `/var/www/NetLab_Guardian/bootstrap.php` | Meldet fehlende SQLite-Dateirechte verständlich |
| `app/Database/db_internal_api.php` | `/var/www/NetLab_Guardian/app/Database/db_internal_api.php` | SQLite-Klasse, migriert auch alte Login-Schemata |
| `INSTALLATION.md` | `/var/www/NetLab_Guardian/INSTALLATION.md` | Anleitung mit Befehlen und Sicherheitshinweisen |
| `README.md` | `/var/www/NetLab_Guardian/README.md` | Projektdokumentation |
| `DATEIPFADE.md` | `/var/www/NetLab_Guardian/DATEIPFADE.md` | Dateiliste und Zielpfade |

Die `.venv`, `backups/` sowie die vorhandene `storage/inventory.sqlite` sind **keine Download-Dateien** und werden vom Installer erstellt beziehungsweise erhalten. Nur die kompletten Projektdateien aus der ZIP in den Hauptordner kopieren. Dann:

```bash
cd /var/www/NetLab_Guardian
sudo bash install.sh --app-user user --web-user www-data --admin admin
```

## Frühere Änderungen: exakter Zielpfad

| Datei in der ZIP | Zielpfad auf deinem Server (relativ zum Projektstamm) | Aufgabe |
| --- | --- | --- |
| `app/Database/db_internal_api.php` | `NetLab-Guardian/app/Database/db_internal_api.php` | Eigentliche Datenbankklasse (`DBInternalAPI`) |
| `db_internal_api.php` | `NetLab-Guardian/db_internal_api.php` | Kompatibilitäts-Loader für alte Aufrufstellen und `setup.php` |
| `bootstrap.php` | `NetLab-Guardian/bootstrap.php` | Prüft Dateiexistenz und lädt die Datenbankklasse |
| `README.md` | `NetLab-Guardian/README.md` | Projektdokumentation |
| `README_INCIDENTS.md` | `NetLab-Guardian/README_INCIDENTS.md` | Check-/Incident-Dokumentation |
| `DATEIPFADE.md` | `NetLab-Guardian/DATEIPFADE.md` | Diese Upload-Anleitung |

## Einmaliger Wechsel von der letzten Version

1. Sichere **`storage/inventory.sqlite`** (falls vorhanden) vor dem Überschreiben von Projektdateien.
2. Entpacke die ZIP in den **Projektstamm**, nicht in `public/` und nicht in einen zusätzlichen Unterordner. Alle ZIP-Einträge haben bereits relative Zielpfade.
3. Achte darauf, dass **`app/Database/db_internal_api.php`** (alles kleingeschrieben) existiert und **`db_internal_api.php`** im Hauptverzeichnis liegt. Beide Dateien gehören zur modularen Version.
4. Die alte Datei **`app/Database/DBInternalAPI.php`** wird nicht mehr benötigt und kann **nach dem Update** entfernt werden. Andere Dateien, insbesondere deine SQLite-Datenbank, nicht löschen.
5. Deine Webserver-DocumentRoot muss auf **`NetLab-Guardian/public/`** zeigen. `bootstrap.php`, `app/` und `storage/` liegen **eine Ebene darüber**.
6. Falls das System noch den alten Dateinamen verwendet: PHP-FPM bzw. OPcache neu starten/leeren und die PHP-Fehlerlogs prüfen.

## Vollständige Dateiliste (relativ zum Projektstamm)

Diese Liste entspricht dem kompletten Download-Paket dieser Version:

```text
.gitignore
DATEIPFADE.md
INSTALLATION.md
README.md
README_INCIDENTS.md
app/Checks/Configuration.php
app/Checks/Execution.php
app/Checks/IncidentResults.php
app/Checks/bootstrap.php
app/Controllers/admin.php
app/Controllers/credentials.php
app/Controllers/dashboard.php
app/Controllers/device.php
app/Controllers/login.php
app/Controllers/site_users.php
app/Database/db_internal_api.php
app/Support/ProjectPaths.php
app/Support/helpers.php
app/Views/dashboard.php
app/Views/dashboard/incidents.php
app/Views/dashboard/inventory.php
app/Views/dashboard/rack_visual.php
app/Views/dashboard/racks.php
app/Views/layout/auth_top.php
app/Views/layout/bottom.php
app/Views/layout/top.php
app/Views/pages/admin.php
app/Views/pages/credentials.php
app/Views/pages/device.php
app/Views/pages/login.php
app/Views/pages/site_users.php
app/Views/rack_controls.php
bootstrap.php
db_internal_api.php
public/admin.php
public/assets/css/admin-and-auth.css
public/assets/css/checks-and-incidents.css
public/assets/css/dashboard.css
public/assets/css/foundation.css
public/assets/css/racks-and-details.css
public/credentials.php
public/device.php
public/index.php
public/login.php
public/logout.php
public/site_users.php
public/style.css
python_scripts/config_parser.py
python_scripts/data_interpreter.py
python_scripts/device_checker.py
python_scripts/example_json.json
python_scripts/main.py
python_scripts/requirements.txt
python_scripts/network_device.py
schema.sql
install.sh
setup.php
storage/.htaccess
```

## Datenbank- und Python-Pfade

| Ressource | Tatsächlicher Pfad |
| --- | --- |
| Datenbank | `storage/inventory.sqlite` |
| SQL-Schema | `schema.sql` |
| Python-Einstiegspunkt | `python_scripts/main.py` |
| DataInterpreter | `python_scripts/data_interpreter.py` |
| Rack-Lock | `storage/rack_check.lock` (wird zur Laufzeit angelegt) |

Wenn ein Fehler angezeigt wird, steht der fehlende **relative Dateipfad** jetzt direkt auf der Startdiagnose. Bei anderen PHP-Problemen bitte die Fehlermeldung aus dem Webserver-/PHP-Log prüfen.
