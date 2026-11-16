# NetLab Guardian installieren (Debian/Ubuntu)

Dieser Installer ist für lokale Linux-Server mit Apache oder PHP-FPM gedacht. Er läuft **ausschließlich im Terminal**, nicht über den Browser. Alle Pfade sind relativ zu `/var/www/NetLab_Guardian` (oder deinem tatsächlichen Projektstamm, der `install.sh` enthält).

## 1. Projekt auf den Server kopieren

Die komplette ZIP **in den bestehenden Projektstamm** entpacken; `storage/inventory.sqlite` vorher sichern und nicht ersetzen. Der Webserver-DocumentRoot zeigt auf `public/` und **nicht** auf den Projektstamm. Die virtuelle Python-Umgebung `.venv` wird *nicht* ausgeliefert, sondern vom Installer erzeugt.

## 2. Installer starten

```bash
cd /var/www/NetLab_Guardian
sudo bash install.sh --app-user user --web-user www-data --admin admin
```

Wenn du als Benutzer `user` angemeldet bist und `sudo` verwendest, reicht:

```bash
sudo bash install.sh
```

Wenn dein PHP-FPM-Pool unter einem anderen Konto als `www-data` läuft, gib diesen Account als `--web-user` an. Welcher User das ist, hängt von deiner Apache-/PHP-FPM-Konfiguration ab. Bei laufendem Dienst zum Beispiel mit `ps -eo user,comm | grep -E 'php-fpm|apache2|httpd'` prüfen; Master-Prozesse laufen teils als root, maßgeblich sind die Worker.

Der Installer erledigt in dieser Reihenfolge:

1. Prüft PHP **ab 8.1** und Python **ab 3.10**, installiert bei Bedarf unter Debian/Ubuntu via `apt-get` `php-cli`, die zur CLI-PHP-Version passende SQLite-Erweiterung (`pdo_sqlite`) und `python3-venv`. Bereits vorhandene Pakete werden nicht unnötig installiert. Für eine Installation ohne apt `--skip-system-packages` angeben; dann müssen alle Systempakete bereits vorhanden sein.
2. Legt das Verzeichnis `storage/` an, setzt es auf **Owner=Deploy-Benutzer, Gruppe=Webserver-Gruppe, Modus 2770** (setgid) und korrigiert den Besitzer/die Gruppe einer bereits existierenden `inventory.sqlite` auf **Deploy-Benutzer:Webserver-Gruppe, Modus 0660**. `.sqlite-wal`, `.sqlite-shm` und `.sqlite-journal` werden bei Bedarf ebenfalls korrigiert. **Kein `chmod 777`.**
3. Erstellt `python_scripts/.venv` **als Deploy-Benutzer**, installiert die Pakete aus `python_scripts/requirements.txt` per `pip` und prüft, ob der Webserver-Benutzer Netmiko importieren kann. Die PHP-Checks verwenden ohne `NETLAB_PYTHON` bereits `python_scripts/.venv/bin/python`.
4. Initialisiert die SQLite-Datenbank (falls sie fehlt) bzw. führt vorhandene Schema-Ergänzungen aus, **ohne Geräte/Incidents zu löschen**. Existiert die Datenbank, wird vor der Migration eine zusätzliche Datei `backups/inventory.sqlite.backup-ZEITSTEMPEL.ZUFALL` angelegt.
5. Legt den Admin-Benutzer an und fragt das Passwort interaktiv und ohne Echo ab. Existiert der Admin schon, wird dessen Passwort **nicht** verändert; verwende zum Zurücksetzen `--reset-admin` oder später `php bin/user.php admin admin`.
6. Prüft Lese- und Schreibrechte beider Benutzer auf die Datenbank, Schreibzugriff auf `storage/`, Leserechte der PHP-Dateien und den Start der `.venv` durch den Webserver-Benutzer. Bei einer fehlgeschlagenen Prüfung bricht der Installer mit Fehlerhinweis ab.

**Wichtig zum Backup:** Die automatisch angelegte Datei ist eine einfache Kopie. Bei Datenbankzugriffen während des Kopierens ist sie nicht garantiert konsistent. Vor einer größeren Aktualisierung die Website/Checks kurz anhalten oder ein konsistentes SQLite-Backup mit einem dafür geeigneten Backup-Verfahren erstellen. Bestehende DB-Dateien werden vom Installer nicht gelöscht. Das Backup enthält auch gespeicherte Zugangsdaten und ist unter `backups/` mit **0700** für das Verzeichnis und **0600** für die Datei vor dem Webserver geschützt.

## 3. Rechte und Voraussetzungen später prüfen

```bash
cd /var/www/NetLab_Guardian
sudo bash install.sh --check --app-user user --web-user www-data
```

Diese Prüfung verändert **keine Dateien**. Für eine nähere Rechte-Diagnose:

```bash
ls -ld /var/www/NetLab_Guardian/storage
ls -l /var/www/NetLab_Guardian/storage/inventory.sqlite
namei -l /var/www/NetLab_Guardian/storage/inventory.sqlite
php -m | grep -i sqlite
```

Beispiel nach erfolgreicher Installation (`user` und `www-data` sind Beispiele):

```text
drwxrws--- user www-data  storage/
-rw-rw---- user www-data  storage/inventory.sqlite
```

Schlägt `php bin/user.php admin admin` mit `SQLSTATE[HY000] [14] unable to open database file` fehl, fehlen meist Rechte auf der Datenbank oder einem übergeordneten Ordner, `storage/` existiert nicht oder es fehlt das SQLite-Modul. Der Installer kontrolliert diese Fälle und repariert die Rechte der genannten Dateien. Sollte die Datenbank nach der Installation weiter nicht erreichbar sein, prüfe das Dateisystem auf Nur-Lese-Modus, SELinux-/AppArmor-Regeln und den tatsächlich verwendeten Webserver-Benutzer.

## 4. Webserver prüfen

Der DocumentRoot muss auf `/var/www/NetLab_Guardian/public/` zeigen. Der Installer **ändert die Apache-/Nginx-Konfiguration nicht**, weil er unbekannte VirtualHosts nicht sicher anpassen kann.

Auch PHP-FPM bzw. `mod_php` kann eine **andere PHP-Version oder php.ini** als die Konsole verwenden. Der Installer prüft CLI-PHP und einen PHP-Start unter dem Webserver-Benutzer, kann aber die konkrete Konfiguration des laufenden FPM-Pools nicht sicher auslesen. Nach dem Installieren einer PHP-Erweiterung den passenden Apache-/PHP-FPM-Dienst neu starten und dessen `pdo_sqlite` überprüfen.

Wenn `NETLAB_PYTHON` in der PHP-Serverumgebung gesetzt ist, überschreibt es die automatisch erzeugte `.venv`; bei Problemen die Variable entfernen oder auf `/var/www/NetLab_Guardian/python_scripts/.venv/bin/python` setzen.

## 5. Wartung und weitere Benutzer

- Passwort ändern oder weiteren Admin anlegen: `php bin/user.php admin admin` (beim direkten CLI-Aufruf muss der Benutzer auf die Datenbank zugreifen können).
- Nur Pakete aktualisieren/Rechte prüfen: `sudo bash install.sh --app-user user --web-user www-data` – die Installation ist wiederholbar.
- Admin-Passwort im Installer zurücksetzen: `sudo bash install.sh --reset-admin --admin admin`.
- Keine Passwörter als Skriptparameter übergeben. Das Admin-Passwort wird interaktiv eingegeben.

Siehe [DATEIPFADE.md](DATEIPFADE.md) für alle Upload-Zielpfade der neuen Dateien. `app/Database/db_internal_api.php` bleibt die eigentliche DB-Klasse, `db_internal_api.php` im Projektstamm nur der Kompatibilitäts-Loader.

## Update von der bisherigen Admin-Seite

Beim Umstieg auf das neue visuelle Dashboard und die getrennten Detailtabellen müssen die folgenden veralteten Dateien manuell entfernt werden (ein Entpacken der ZIP löscht sie nicht automatisch):

```bash
cd /var/www/NetLab_Guardian
rm -- public/admin.php app/Controllers/admin.php app/Views/pages/admin.php
```

Die Geräteverwaltung ist danach über `public/device.php` erreichbar. `public/details.php` zeigt die Tabellen für Geräte und Incidents. Die Datenbank und die Python-Checks werden nicht geändert.
