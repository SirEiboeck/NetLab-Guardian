# NetLab Guardian

Kompaktes PHP-/SQLite-Dashboard zur Verwaltung und Prüfung von Labor-Netzwerkgeräten. Diese Version baut auf der zuletzt korrigierten `netlab.zip` auf. **Die Python-Checks und die Incident-Synchronisierung wurden nicht durch ein neues System ersetzt.** Das UI wurde stärker als Inventar-/Incident-Tabelle aufgebaut; die Rack-Grafik ist weiter verfügbar, aber standardmäßig eingeklappt.

## Neuer CLI-Installer (empfohlen)

Nach dem Kopieren der Dateien auf den Debian-/Ubuntu-Server **nicht mehr direkt mit `php setup.php` beginnen**, sondern zuerst den Installer ausführen:

```bash
cd /var/www/NetLab_Guardian
sudo bash install.sh --app-user user --web-user www-data --admin admin
```

Der Installer kümmert sich um PHP-SQLite, `storage/`-/DB-Berechtigungen, `python_scripts/.venv`, Netmiko und den ersten Admin. Ein vorhandener Admin bleibt unverändert. Er überprüft anschließend die Zugriffsrechte aus Sicht des tatsächlichen Webserver-Benutzers. Weitere Informationen und der reine Diagnosemodus `sudo bash install.sh --check`: [INSTALLATION.md](INSTALLATION.md).

## Verzeichnisstruktur

```text
NetLab-Guardian/
├── app/
│   ├── Checks/
│   │   ├── Configuration.php       # Check-JSON, Credentials, Discovery-Pläne
│   │   ├── Execution.php           # Python-Aufruf, Batches, Fehlerbehandlung
│   │   ├── IncidentResults.php     # Python-Antworten und Incident-Ergebnisse
│   │   └── bootstrap.php           # lädt die drei Check-Module
│   ├── Controllers/                # POST-Verarbeitung und Seiten-Daten
│   │   ├── dashboard.php
│   │   ├── admin.php
│   │   ├── credentials.php
│   │   ├── device.php
│   │   ├── site_users.php
│   │   └── login.php
│   ├── Database/db_internal_api.php  # SQLite-API, Migrationen, Incident-Abgleich
│   ├── Support/                   # Auth, CSRF und einheitliche Projektpfade
│   │   ├── helpers.php
│   │   └── ProjectPaths.php
│   └── Views/
│       ├── dashboard.php           # Dashboard-Zusammenstellung
│       ├── dashboard/              # Tabellen + Rack-Ansichten
│       ├── layout/                 # gemeinsame Navigation, Head, Footer
│       ├── pages/                  # Geräte-, Admin- und Login-Templates
│       └── rack_controls.php       # Rack-Sperren und Controls
├── public/
│   ├── index.php                   # schlanker Dashboard-Einstiegspunkt
│   ├── device.php, admin.php, ...  # bestehende öffentliche URLs
│   ├── style.css                   # importiert die CSS-Module
│   └── assets/css/                 # Design, Seiten, Checks, Tabellen
├── python_scripts/                # DataInterpreter + requirements.txt, .venv (lokal)
├── storage/                       # SQLite-Datenbank, Check-Lock
├── backups/                       # Installer-Backups (0700, lokal erstellt)
├── bootstrap.php                   # Startprüfung, DB und Session initialisieren
├── db_internal_api.php            # Kompatibilitäts-Loader für die DB-Klasse
├── schema.sql                     # Datenbankschema
└── setup.php                      # CLI-Benutzerverwaltung
```

## Wichtig: Dateinamen und Zielpfade (Linux unterscheidet Groß-/Kleinschreibung)

Die ursprüngliche API-Datei heißt **`db_internal_api.php`**. In der modularen Version gibt es zwei Dateien mit diesem Namen, aber verschiedenen Aufgaben:

| Datei relativ zum Projektstamm | Zweck |
| --- | --- |
| `db_internal_api.php` | Sehr kleiner Kompatibilitäts-Loader. Wird von `bootstrap.php` und `setup.php` eingebunden. |
| `app/Database/db_internal_api.php` | **Eigentliche** PHP-Klasse `DBInternalAPI`, alle SQLite-Funktionen und Incident-Abgleich. |
| `app/Support/ProjectPaths.php` | Liefert die absoluten Pfade zu Datenbank, Schema, Python und Rack-Sperrdatei. |
| `bootstrap.php` | Prüft die benötigten Dateien und lädt die Datenbank-API. |

Der früher von mir eingeführte Name `app/Database/DBInternalAPI.php` wird **nicht mehr verwendet**. Wenn du von der letzten Refactoring-Version aktualisierst, kopiere `app/Database/db_internal_api.php` neu an die angegebene Stelle und entferne nach einer erfolgreichen Aktualisierung die alte Datei `app/Database/DBInternalAPI.php`. Auf Linux sind das zwei unterschiedliche Namen. Die PHP-**Klasse** heißt weiterhin `DBInternalAPI`; nur der **Dateiname** ist kleingeschrieben.

Vollständige Pfadliste und eine Upload-Anleitung: [DATEIPFADE.md](DATEIPFADE.md).

## Was sich im UI geändert hat

- Gemeinsame Seitennavigation statt verschiedener Header auf jeder Seite.
- Kompakter Dashboard-Kopf mit Kennzahlen zu Geräten und offenen Incidents.
- Incident-Tabelle mit Status, Gerät, Check, Beschreibung und Zeitstempeln sowie den Filtern **Offen / Gelöst / Alle**.
- Geräteinventar als durchsuchbare Tabelle mit Management-IP, Rack, Status und letztem Prüfzeitpunkt.
- Racks zuerst als Tabelle mit **Geräte prüfen** und **IP-Scan + Prüfen**; ausführliche Rack-Zeichnungen und JSON-Einstellungen in aufklappbaren Abschnitten.
- Gerätedetails: Störungen ebenfalls als Tabelle mit manueller Behebung für Admins.
- Mobile Darstellung mit scrollbareren Tabellen und einer kompakten Navigation. Es wird bewusst kein JavaScript benötigt.

## Installation / Upgrade

1. **SQLite-Datei sichern:** `storage/inventory.sqlite` auf dem Server vor dem Austausch sichern. Die ZIP enthält keine solche Datei.
2. **Code komplett aktualisieren:** `app/`, `public/`, `bootstrap.php`, `db_internal_api.php` und die übrigen Projektdateien in dein Projekt übernehmen. Beim Upgrade nicht nur `public/index.php` kopieren – das benötigt jetzt die Controller und Views in `app/`.
3. Der Webserver sollte **`public/` als DocumentRoot** verwenden. PHP benötigt `pdo_sqlite`; für Netzwerkprüfungen werden die Python-Abhängigkeiten (insbesondere `netmiko`) und der konfigurierte Interpreter benötigt.
4. Bestehende Links (`index.php`, `device.php?id=…`, `admin.php`, `credentials.php`, `site_users.php`) funktionieren unverändert. An Datenbanktabellen, Scan-JSON und dem Python-Interpreter sind keine migrationspflichtigen Änderungen vorgenommen worden.
5. Falls das CSS im Browser noch alt aussieht: Cache neu laden. `public/style.css` lädt jetzt `public/assets/css/*.css`.

## Wartung

- **Neuer Check:** `python_scripts/data_interpreter.py` mit `@command_check(...)` erweitern und den CLI-Befehl in der Tabelle `command` oder im Rack-JSON eintragen. Hinweise zu `Finding(key=...)` in [README_INCIDENTS.md](README_INCIDENTS.md).
- **UI ändern:** Die Tabellen liegen unter `app/Views/dashboard/`; globale Navigation unter `app/Views/layout/`; neue CSS-Regeln in `public/assets/css/dashboard.css`.
- **Prüfablauf ändern:** `app/Checks/` und `app/Controllers/dashboard.php`. Zugangsdaten niemals im Template ausgeben.
- **DB und Incidents ändern:** `app/Database/db_internal_api.php`. `db_internal_api.php` im Projektstamm ist nur der Kompatibilitäts-Loader; die Implementierung liegt unter `app/Database/db_internal_api.php` (Dateiname ist absichtlich kleingeschrieben).
- **Neue Seite:** eine Datei unter `public/` als Einstiegspunkt, Controller unter `app/Controllers/`, View unter `app/Views/pages/`. Der Controller wird vor der View ausgeführt; die View bindet `layout/top.php` und `layout/bottom.php` ein.

Die Prüfung der PHP-Syntax ist möglich mit `find . -name '*.php' -print0 | xargs -0 -n1 php -l`. Für eine vollständige Funktionsprüfung der SQLite-Synchronisierung muss PHP mit `pdo_sqlite` installiert sein; echte Switch-/Router-Checks benötigen Netzwerkzugriff.

## HTTP-500-Fehler und fehlende Projektdateien beheben

Diese Version korrigiert zwei fehlerhafte Verzeichnispfade, die beim Aufteilen der Dateien entstanden waren:

- Rack-Aktionen benutzen `ProjectPaths::rackLock()` und damit `<Projekt>/storage/rack_check.lock` statt `app/storage/rack_check.lock`.
- Die Benutzerverwaltung öffnet dieselbe Datenbank wie das Dashboard: `<Projekt>/storage/inventory.sqlite` statt `app/storage/inventory.sqlite`.

Die Pfade zu Datenbank, Schema, Python-Skripten und Rack-Lock liegen zentral in `app/Support/ProjectPaths.php`. Beim Start prüft `bootstrap.php`, ob die wichtigsten Projektdateien vorhanden, die PHP-Erweiterung `pdo_sqlite` geladen und `storage/` beschreibbar ist. Bekannte Konfigurationsprobleme zeigen eine kurze Hilfeseite (HTTP 503); technische Details werden ausschließlich im PHP-Fehlerlog protokolliert. Das Diagnoseverhalten ersetzt **nicht** das Prüfen der Logs bei weiterhin auftretendem HTTP 500.

**Upgrade:** Kopiere sämtliche Inhalte der ZIP *in deinen bestehenden Projektordner* – nicht den Ordner `NetLab-Guardian-UI-Fix500` als Unterordner. Der Webserver-DocumentRoot bleibt `<Projekt>/public`. Übernimm insbesondere `app/` vollständig, `bootstrap.php`, `db_internal_api.php` und `public/` zusammen. Die vorhandene Datenbank `storage/inventory.sqlite` vorher sichern und beim Upload nicht ersetzen. Eine bereits vorhandene SQLite-Datei bleibt bei diesem Update unangetastet.

Wenn die Anwendung weiterhin HTTP 500 anzeigt, stehen die genauen Ursachen im Webserver/PHP-FPM-Fehlerlog, z. B. je nach Installation unter `/var/log/apache2/error.log` oder im Journal des PHP-FPM-Dienstes. Prüfe auch die **Webserver-PHP-Version** auf `pdo_sqlite` sowie Zugriffsrechte auf `storage/`. Nach dem Austausch kann ein Neustart von PHP-FPM bzw. das Leeren von OPcache nötig sein. Das Browser-Schlosssymbol „Nicht sicher“ hängt dagegen mit deinem lokalen HTTPS-Zertifikat zusammen und ist unabhängig vom HTTP-500-Fehler.
