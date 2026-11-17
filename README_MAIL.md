# NetLab Guardian – täglicher Incident-Mail-Service über SMTP

Dieser Backend-Dienst prüft täglich zur gewünschten Uhrzeit **zuerst alle bekannten,
aktiven und nicht ausgeschlossenen Geräte** über die vorhandenen Python-/Netmiko-Checks.
Die Ergebnisse und die offenen/gelösten Incidents werden in SQLite aktualisiert.
**Erst nach Abschluss des Gerätechecks** verschickt er den Bericht mit allen
aktuell offenen Incidents, gruppiert nach Geräten (Hostname, IP, Typ, Beschreibung,
Zeitpunkte). Er nutzt die vorhandene `DBInternalAPI`; die Mailversandfunktion benötigt keine Änderungen an der Website
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

Der Befehl **führt keine Geräteprüfungen aus** und verändert keine Incidents.
Er liest ausschließlich die **bereits in SQLite gespeicherten offenen Incidents**,
gruppiert sie nach Geräten und verschickt sie mit **dem gleichen Betreff, HTML-/Textlayout,
Absender und denselben Empfängern** wie der geplante Versand.
Die Zusammenfassung eines *gerade durchgeführten* Gerätechecks entfällt beim Testversand,
weil kein neuer Check stattfindet.
Sind keine offenen Incidents vorhanden, enthält die E-Mail die entsprechende
Leermeldung, genauso wie beim täglichen Bericht.

Auch `sudo systemctl start netlab-incident-mail-now.service` führt zuerst alle Gerätechecks aus.

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

## Neu: HTML-Bericht im Dashboard-Stil

Die automatische Mail **und** `--test-mail` enthalten jetzt denselben HTML-Bericht:

- Status-Kennzahlen und zwei Kreisdiagramme für Geräte (Geprüft, Warnung,
  Kritisch, Nicht geprüft) und Incidents (Offen/Gelöst).
- Offene Incidents nach Geräten gruppiert, mit Gerät, IP, Modell, Firmware,
  Seriennummer, Fehlerquelle, Meldung und Zeitpunkten.
- Outlook-freundliches HTML mit Inline-Styles, Tabellenlayout und eingebetteten
  PNG-Diagrammen via Content-ID; zusätzlich gibt es die vollständige Textversion.
  Es werden keine externen Bilder oder Tracking-Dienste verwendet.
- Die Diagramme werden mit **PHP-GD** direkt auf dem Server erstellt. Der
  normale Installer installiert dafür `php-gd`. Ohne diese Erweiterung bleiben
  die HTML-Kennzahlen und Legenden sichtbar, aber es gibt kein Donut-Bild.
- Das SMTP-Backend benötigt keine eigenen Frontend-Komponenten. Die Website enthält unabhängig davon neue Inventar- und Incident-Aktionen. Gerätestatistiken
  verwenden dieselbe `statusFor()`-Logik wie das Dashboard.

## Neu: Mail-Einrichtung direkt während der Installation

Beim ersten interaktiven `sudo bash install.sh` fragt der Installer, ob er die
Mailfunktion jetzt einrichten soll. Du gibst in der Konsole SMTP-Host, Port,
STARTTLS/SSL, E-Mail-Absender, optionalen Login-Namen, kommaseparierte
Empfänger, Zeitzone und tägliche Versandzeit ein. Das Passwort wird verdeckt
separat abgefragt und über `systemd-creds` oder root-only-Dateirechte unter
`/etc/netlab-guardian` geschützt. Die Mail-Konfiguration liegt unter
`config/incident_mail.php` mit Rechten `0600` und enthält **kein Passwort**.

Du kannst den Einrichtungsassistenten jederzeit erneut öffnen:

```bash
sudo bash install.sh --setup-mail
```

Der Assistent ersetzt eine vorhandene Mail-Konfiguration nach erfolgreicher
Eingabe, übernimmt aber nicht automatisch ältere Werte als Vorschläge. Wenn du
nur die Uhrzeit in `config/incident_mail.php` geändert hast, genügt weiterhin:

```bash
sudo bash install.sh --mail-only
```

Wird der Installer ohne interaktives Terminal ausgeführt, überspringt er den
Assistenten und richtet die Mailfunktion nur ein, wenn gültige Zugangsdaten
vorliegen. Ein fehlerhaftes SMTP-Passwort oder eine Provider-Sperre kann durch
den Installer nicht automatisch erkannt bzw. umgangen werden.

## Dunkle HTML-Berichte und scharfe Diagramme

Der HTML-Bericht benutzt eine durchgehend dunkle Dashboard-Farbpalette (dunkler
Hintergrund, helle Texte, farbige Status-Kacheln) und Outlook-kompatible
Tabellen. Die Kreisdiagramme werden durch `IncidentMailCharts` als **440×440
Pixel große PNGs** in dreifacher Zeichenauflösung vorbereitet und eingebettet;
bei 190 Pixel Anzeigebreite ergibt das eine hochauflösende Darstellung. Für
Schriften werden optional lokale Systemschriften verwendet. Der Installer
installiert nach Möglichkeit `php-gd`; ohne GD erscheinen die Kennzahlen und
Legenden als Fallback. Outlook kann im systemeigenen Dunkelmodus einzelne Farben
selbst anpassen.

Ein manuell ausgelöster Bericht mit `sudo bash install.sh --test-mail`
verwendet dasselbe Layout und die gespeicherten Gerätedaten/Incidents,
führt aber bewusst **keinen** vorherigen Gerätecheck aus.

## Gerätecheck vor dem automatischen Versand

Der systemd-Timer startet `netlab-incident-mail.service` **einmal täglich** zur
konfigurierten Uhrzeit. Dieser Dienst verwendet die bestehenden Prüf-Funktionen
`rackEditableConfig()`, `rackExecutionPlans()`, `runRackPlans()` und
`saveRackCheckResults()` für **alle bereits bekannten aktiven Geräte**, auch
wenn sie keiner Rack-Position zugeordnet sind. Er verwendet die Datenbank-
Credentials sowie die in der Datenbank gepflegten Diagnosebefehle. Wie der
Web-Button führt Python zusätzlich die Inventarisierung (Hostname, Modell,
Firmware, Seriennummer) aus. Es gibt **keinen automatischen IP-/Discovery-Scan**.

Geräte mit aktivierter Prüfungssperre (`check_excluded`) werden respektiert;
Geräte ohne gültige Management-IP können nicht abgefragt werden. Beides steht
mit den Erfolgs-/Fehlerzahlen im Mailbericht. Nicht erreichbare Geräte oder
Anmeldefehler werden als Incidents gespeichert und erscheinen im Bericht.
Verbindungsfehler dürfen alte Hardwarefehler nicht fälschlich auflösen.

Ein vollständiger Lauf muss beendet sein, **bevor die Mail erstellt wird**.
Scheitert der Gesamtcheck technisch (z. B. Python fehlt, keine Credentials,
Check läuft bereits oder unerwarteter Datenbankfehler), wird **keine veraltete
Mail verschickt**. Fehler stehen im systemd-Journal. Einzelne ausgefallene
Geräte verhindern die Mail dagegen nicht: sie erzeugen Check-Incidents.

Der Maildienst nutzt die bestehende `storage/rack_check.lock`-Datei und läuft
daher nicht gleichzeitig mit manuellen Rack-Checks. Der geplante Versand
wartet auf einen parallel gestarteten Check oder Mailversand; ein manueller
Test meldet stattdessen eine belegte Sperre. Für die gesamte Ausführung
inklusive Python-Checks und SMTP gilt `TimeoutStartSec=45min` in den systemd-Units.
Beim `--test-mail`-Versand werden keine Gerätechecks gestartet; er nutzt nur
bestehende SQLite-Daten. Der sofortige Bericht via `netlab-incident-mail-now.service`
führt dagegen weiterhin einen Check durch. `--dry-run` zeigt ebenfalls nur
den gespeicherten Bericht und führt **keinen** Gerätecheck aus.

Nach einem Update die systemd-Units aktualisieren:

```bash
sudo bash install.sh --mail-only
sudo bash install.sh --test-mail
sudo journalctl -u netlab-incident-mail-test.service -n 50 --no-pager
```
