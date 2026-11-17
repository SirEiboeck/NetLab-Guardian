# NetLab Guardian – täglicher Incident-Mail-Service über SMTP

Dieser Backend-Dienst verschickt täglich zur gewünschten Uhrzeit einen Bericht mit **allen
aktuell offenen Incidents**, gruppiert nach Geräten (Hostname, IP, Typ, Beschreibung,
Zeitpunkte). Er nutzt die vorhandene `DBInternalAPI`, verändert das Frontend nicht
und benötigt weder eine Microsoft-Entra-App noch Tenant- oder Client-IDs.

## Wichtige Voraussetzung: Dein SMTP-Anbieter muss Passwort-Login erlauben

**Microsoft 365 / Schulkonto:** Der Server `smtp.office365.com` (Port 587, STARTTLS)
unterstützt Anmeldung per E-Mail + Passwort nur, **wenn SMTP AUTH und die
Passwortauthentifizierung für dieses Konto und den Tenant zugelassen sind**.
Das ist bei vielen Schulen gesperrt. Microsoft stellt die klassische Basic-Auth-
Anmeldung für Exchange Online ein. Ohne Freischaltung funktioniert der Versand
nicht – das Programm kann eine serverseitige Sperre nicht umgehen.

**Privates Outlook.com-Konto:** Das unterstützt die Anmeldung per Benutzername und
Passwort über SMTP nicht mehr; hier ist OAuth2 nötig. Für die reine Passwortlösung
kannst du einen anderen SMTP-Anbieter verwenden, der **SMTP AUTH LOGIN oder PLAIN
über TLS** und gegebenenfalls ein **App-Passwort** unterstützt.

Du benötigst in jedem Fall nur SMTP-Host, Port, deine Absender-E-Mail und ein
SMTP-Passwort (oder App-Passwort) sowie die Empfänger und Versandzeit.

## Installation und Konfiguration

Gesamtprojekt auf Debian/Ubuntu auspacken und wie bisher installieren:

```bash
sudo bash install.sh
```

Der Installer legt `config/incident_mail.php` aus der Vorlage an. Passe sie an:

```php
return [
    'timezone'       => 'Europe/Vienna',
    'send_time'      => '07:00',
    'smtp_host'      => 'smtp.office365.com', // falls von der Schul-IT freigeschaltet
    'smtp_port'      => 587,
    'smtp_encryption'=> 'starttls',
    'sender'         => 'dein-postfach@schule.at',
    'recipients'     => ['admin@schule.at'],
];
```

Wenn SMTP-Benutzername und Absenderadresse unterschiedlich sind, kann optional
`'smtp_username' => 'smtp-login@example.com'` gesetzt werden.

**Passwort NIEMALS in die PHP-Datei schreiben.** Stattdessen im Terminal eingeben:

```bash
sudo bash install.sh --set-mail-password
```

Das Passwort wird ohne Echo und ohne Shell-History-Eintrag abgefragt und außerhalb
des Projekts abgelegt:

- Ist `systemd-creds` verfügbar, **verschlüsselt** in
  `/etc/netlab-guardian/smtp-password.cred`, root-only.
- Sonst in `/etc/netlab-guardian/smtp-password` mit Berechtigungen **0600**, root-only.
- Der Dienst erhält es ausschließlich als systemd-Credential unter
  `$CREDENTIALS_DIRECTORY/smtp_password`. Die Website hat keinen Zugriff darauf.
- **Root oder Angreifer mit vollständigem Serverzugriff können Geheimnisse auf
  einem laufenden System grundsätzlich auslesen**. Vollständigen Schutz gibt es nicht.

Nach Änderung der Konfiguration (insbesondere `send_time` und `timezone`)
oder erneuter Passwort-Eingabe:

```bash
sudo bash install.sh --mail-only
```

Der Timer wird nur aktiviert, wenn die Konfiguration gültig und ein Passwort
hinterlegt ist. `install.sh` schreibt die konfigurierte Uhrzeit und Zeitzone
als `OnCalendar`-Ausdruck in den systemd-Timer. systemd startet den Service
damit **einmal täglich** (keine minütliche PHP-Prüfung). Der Versand erfolgt
höchstens einmal erfolgreich pro Kalendertag. Ohne offene Incidents
kommt eine entsprechende Leermeldung. Bei SMTP-Fehlern wird der Bericht nicht
als „gesendet“ markiert. Um Kontosperren durch wiederholte falsche Passwörter
zu vermeiden, erfolgt frühestens nach 4 Stunden ein automatischer Neuversuch.

## Den normalen Incident-Bericht sofort aus der Kommandozeile versenden

Mit `--test-mail` verschickst du **denselben Bericht wie beim automatischen Tagesversand**,
sofort und unabhängig von der eingestellten Uhrzeit:

```bash
sudo bash install.sh --test-mail
```

Der Befehl liest die **aktuell offenen Incidents** aus der vorhandenen Datenbank,
gruppiert sie nach Geräten und versendet sie mit **dem gleichen Betreff, Inhalt,
Absender, SMTP-Passwort und denselben Empfängern** wie der geplante Versand.
Sind keine offenen Incidents vorhanden, enthält die E-Mail die entsprechende
Leermeldung, genauso wie beim täglichen Bericht.

Wichtig: Dieser manuelle Testversand **ändert nicht** die Datei
`storage/incident_mail_last_sent.txt` und verhindert somit nicht, dass der
systemd-Timer den Tagesbericht später erneut verschickt. Auch die
Timer-Einstellungen bleiben unverändert. Läuft gerade ein anderer Mailversand,
wird der Testversand mit einer Fehlermeldung abgebrochen.

`install.sh` startet dafür den systemd-Testdienst. Das Passwort bleibt
als geschütztes systemd-Credential gespeichert und wird nicht als
Kommandozeilenargument übergeben.

Du kannst denselben Versand auch direkt über systemd anstoßen:

```bash
sudo systemctl start netlab-incident-mail-test.service
```

Ein erfolgreicher SMTP-Aufruf bedeutet, dass der SMTP-Server den Bericht
angenommen hat, nicht unbedingt, dass er bereits im Posteingang liegt.
Für Fehlermeldungen:

```bash
sudo journalctl -u netlab-incident-mail-test.service -n 50 --no-pager
```

Nach einem Update von einer älteren Version führe einmalig
`sudo bash install.sh --mail-only` aus, damit die aktualisierte
systemd-Unit installiert wird.

## Bedienung

```bash
sudo systemctl status netlab-incident-mail.timer
systemctl list-timers netlab-incident-mail.timer   # nächste geplante Ausführung
sudo journalctl -u netlab-incident-mail.service -n 50 --no-pager
sudo bash install.sh --test-mail                  # echten Incident-Bericht sofort senden, ohne Tagesstatus zu ändern
sudo systemctl start netlab-incident-mail-now.service  # Bericht sofort senden und als heute versendet markieren
sudo journalctl -u netlab-incident-mail-now.service -n 50 --no-pager
sudo systemctl disable --now netlab-incident-mail.timer
```

`php bin/incident_mail.php --dry-run` zeigt den Bericht ohne Versand und
`php bin/incident_mail.php --check` prüft die Konfiguration. Für echten Versand
wird aus Sicherheitsgründen der systemd-Dienst verwendet.

## Wechsel von der Microsoft-Graph-Version

Falls bereits `config/incident_mail.php` mit `tenant_id` und `client_id` existiert,
bleibt diese Datei bewusst erhalten, um deine Einstellungen nicht zu überschreiben.
Ersetze dort die alte Graph-Konfiguration durch die SMTP-Einträge aus der Vorlage.
Das alte Microsoft-Graph-Client-Secret unter `/etc/netlab-guardian/client-secret*`
wird **nicht** weiterbenutzt. Falls es nicht mehr benötigt wird, kann der
Administrator es nach Prüfung entfernen. Hinterlege das **SMTP-Passwort** mit
`--set-mail-password`. Es darf nicht im Git-Repository, in Backups oder in
`config/incident_mail.php` liegen.

Für einen anderen Mailanbieter passen `smtp_host`, `smtp_port` und
`smtp_encryption` dessen offiziellen SMTP-Angaben entsprechend an.
