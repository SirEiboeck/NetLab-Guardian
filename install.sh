#!/usr/bin/env bash
# NetLab Guardian: sichere Erstinstallation und Wiederholungsinstallation für Debian/Ubuntu.
# Bewusst nur lokal als root via sudo ausführen; niemals über einen Web-Endpunkt.
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
WEB_USER=www-data
APP_USER="${SUDO_USER:-}"
ADMIN_USER=admin
SKIP_PACKAGES=0
CHECK_ONLY=0
RESET_ADMIN=0
MAIL_ONLY=0
SET_MAIL_PASSWORD=0
TEST_MAIL=0
CONFIGURE_MAIL=0

usage() {
    cat <<'HELP'
NetLab Guardian Installer (Debian/Ubuntu)
Aufruf: sudo ./install.sh [Optionen]
  --web-user NAME          PHP-FPM-/Apache-Prozessbenutzer (Standard: www-data)
  --app-user NAME          Besitzer/Deploy-Benutzer (Standard: sudo-Aufrufer)
  --admin NAME             Admin-Login (Standard: admin)
  --reset-admin            Passwort des Admins erneut abfragen
  --skip-system-packages   Kein apt-get; vorhandene Pakete nur prüfen
  --check                  Nur Zugriffe/Abhängigkeiten überprüfen; nichts ändern
  --mail-only              Mail-Konfiguration und systemd-Timer übernehmen
  --setup-mail             SMTP-Einrichtungsassistent (E-Mail, Empfänger, Zeit, Passwort)
  --test-mail              Incident-Bericht aus der DB sofort senden, ohne Gerätecheck oder Tagesstatusänderung
  --set-mail-password      SMTP-Passwort verdeckt abfragen und geschützt speichern
  --set-mail-secret        Veralteter Alias für --set-mail-password
  --help                   Hilfe anzeigen
Beispiele:
  sudo ./install.sh --web-user www-data --app-user user --admin admin
  sudo ./install.sh --mail-only --app-user user --web-user www-data
  sudo ./install.sh --test-mail
  sudo ./install.sh --setup-mail

Der Installer erstellt python_scripts/.venv und prüft PHP-PDO-SQLite,
Rechte sowie den Start des Python-Interpreters durch den Webserverbenutzer.
Der tägliche Incident-Mail-Timer startet zunächst die bestehenden Python-Gerätechecks
für alle bekannten, freigegebenen Geräte und verschickt danach den aktualisierten Bericht. Beim ersten Lauf entsteht
config/incident_mail.php; das SMTP-Passwort wird mit --set-mail-password separat
verschlüsselt (sofern systemd-creds vorhanden ist) oder root-geschützt gespeichert.
Beim ersten interaktiven Installationslauf bietet der Assistent die vollständige
Mail-Einrichtung an. Später: --setup-mail, danach --test-mail.
Bestehende Geräte und Incidents in storage/inventory.sqlite bleiben erhalten.
HELP
}
while (( $# )); do
    case "$1" in
        --web-user|--app-user|--admin)
            if (( $# < 2 )); then echo "Für $1 fehlt ein Wert." >&2; exit 2; fi
            case "$1" in
                --web-user) WEB_USER="$2" ;;
                --app-user) APP_USER="$2" ;;
                --admin) ADMIN_USER="$2" ;;
            esac
            shift 2 ;;
        --reset-admin) RESET_ADMIN=1; shift ;;
        --skip-system-packages) SKIP_PACKAGES=1; shift ;;
        --check) CHECK_ONLY=1; shift ;;
        --mail-only) MAIL_ONLY=1; shift ;;
        --setup-mail) CONFIGURE_MAIL=1; shift ;;
        --test-mail) TEST_MAIL=1; shift ;;
        --set-mail-password|--set-mail-secret) SET_MAIL_PASSWORD=1; shift ;;
        --help|-h) usage; exit 0 ;;
        *) echo "Unbekannte Option: $1" >&2; usage >&2; exit 2 ;;
    esac
done
say() { printf '\n\033[1;36m[NetLab]\033[0m %s\n' "$*"; }
fail() { printf '\n[FEHLER] %s\n' "$*" >&2; exit 1; }
need_file() { [[ -f "$ROOT/$1" ]] || fail "Datei fehlt: $ROOT/$1"; }
[[ $(id -u) -eq 0 ]] || fail 'Bitte mit sudo ./install.sh ausführen (für chown, Rechte und apt).'
[[ -n "$APP_USER" ]] || fail 'Deploy-Benutzer fehlt: --app-user NAME angeben (oder per sudo starten).'
id "$WEB_USER" >/dev/null 2>&1 || fail "Webserver-Benutzer '$WEB_USER' existiert nicht. --web-user angeben."
id "$APP_USER" >/dev/null 2>&1 || fail "Deploy-Benutzer '$APP_USER' existiert nicht. --app-user angeben."
[[ "$APP_USER" != root ]] || fail 'Nicht als root ins Projekt installieren. --app-user mit einem normalen Benutzer setzen.'
[[ "$WEB_USER" != root ]] || fail 'Ein Webserver sollte nicht als root laufen. --web-user korrigieren.'
[[ "$APP_USER" != "$WEB_USER" ]] || fail 'Deploy- und Webserver-Benutzer müssen getrennt sein.'
[[ "$ADMIN_USER" =~ ^[a-zA-Z0-9_.-]{3,40}$ ]] || fail 'Admin-Benutzername muss 3–40 Zeichen haben (a-z, 0-9, _, ., -).'
command -v runuser >/dev/null 2>&1 || fail 'runuser fehlt (Paket util-linux).'
WEB_GROUP="$(id -gn "$WEB_USER")"
STORAGE="$ROOT/storage"
BACKUPS="$ROOT/backups"
DB="$STORAGE/inventory.sqlite"
VENV="$ROOT/python_scripts/.venv"
VENV_PY="$VENV/bin/python"

as_app() { runuser -u "$APP_USER" -- "$@"; }
as_web() { runuser -u "$WEB_USER" -- "$@"; }
for f in schema.sql bin/user.php db_internal_api.php app/Database/db_internal_api.php app/Controllers/incident_actions.php app/Support/ProjectPaths.php public/index.php python_scripts/main.py python_scripts/device_metadata.py python_scripts/requirements.txt; do
    need_file "$f"
done
[[ ! -L "$STORAGE" && ! -L "$DB" && ! -L "$VENV" ]] || fail 'storage/, SQLite-Datei oder .venv ist ein Symlink; bitte manuell prüfen.'
php_has_sqlite() { command -v php >/dev/null 2>&1 && php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1; }
python_ok() { command -v python3 >/dev/null 2>&1 && python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 10) else 1)' >/dev/null 2>&1; }

check_install() {
    say 'Überprüfe Installation'
    local errors=0
    if php_has_sqlite; then echo '  OK: PHP CLI und pdo_sqlite'; else echo '  FEHLT: php CLI / pdo_sqlite'; errors=1; fi
    if python_ok; then echo '  OK: Python >= 3.10'; else echo '  FEHLT: Python >= 3.10'; errors=1; fi
    if [[ -f "$DB" ]]; then
        if as_app test -r "$DB" && as_app test -w "$DB" && as_web test -r "$DB" && as_web test -w "$DB"; then
            echo '  OK: Datenbank für Deploy-Benutzer und Webserver les-/schreibbar'
        else
            echo "  FEHLER: Dateirechte für $DB"; errors=1
        fi
    else
        echo "  FEHLT: $DB"; errors=1
    fi
    if as_app test -w "$STORAGE" && as_web test -w "$STORAGE"; then
        echo '  OK: storage/ für beide Benutzer beschreibbar'
    else
        echo '  FEHLER: storage/ ist nicht für beide Benutzer beschreibbar'; errors=1
    fi
    if as_web test -r "$ROOT/public/index.php" && as_web test -r "$ROOT/app/Database/db_internal_api.php"; then
        echo '  OK: Webserver kann Website/Backend lesen'
    else
        echo '  FEHLER: Webserver kann Website/Backend nicht lesen (ggf. übergeordnete Ordner prüfen)'; errors=1
    fi
    if [[ -f "$DB" ]] && php_has_sqlite && as_web php -r '$pdo = new PDO("sqlite:" . $argv[1]); $pdo->query("SELECT name FROM sqlite_master LIMIT 1");' "$DB" 2>/dev/null; then
        echo '  OK: Webserver-Benutzer kann die Datenbank mit PHP öffnen'
    else
        echo '  FEHLER: PHP-Datenbankzugriff als Webserver-Benutzer fehlgeschlagen'; errors=1
    fi
    if as_web test -x "$VENV_PY" && as_web "$VENV_PY" -c 'import netmiko; from netmiko.exceptions import NetmikoAuthenticationException, NetmikoTimeoutException' 2>/dev/null; then
        echo '  OK: Webserver kann die Python-.venv mit Netmiko verwenden'
    else
        echo '  FEHLER: .venv fehlt oder ist für Webserver nicht ausführbar'; errors=1
    fi
    if (( errors )); then
        echo '  Hinweis: sudo ./install.sh ausführen, um die Installation zu korrigieren.'
        return 1
    fi
    echo '  HINWEIS: PHP-FPM/Apache kann ein anderes PHP als CLI verwenden; pdo_sqlite dort separat prüfen.'
    return 0
}

# SMTP-Passwort ausschließlich außerhalb des Projekts ablegen, niemals in Git.
MAIL_PASSWORD_DIR=/etc/netlab-guardian
MAIL_PASSWORD_ENCRYPTED="$MAIL_PASSWORD_DIR/smtp-password.cred"
MAIL_PASSWORD_PLAIN="$MAIL_PASSWORD_DIR/smtp-password"

set_mail_password() {
    say 'SMTP-Passwort geschützt einrichten'
    [[ -r /dev/tty ]] || fail 'Für --set-mail-password wird eine interaktive Konsole benötigt.'
    [[ ! -L "$MAIL_PASSWORD_DIR" && ! -L "$MAIL_PASSWORD_ENCRYPTED" && ! -L "$MAIL_PASSWORD_PLAIN" ]] \
        || fail 'Passwort-Pfade dürfen keine Symlinks sein.'
    install -d -o root -g root -m 0700 "$MAIL_PASSWORD_DIR"
    local secret
    IFS= read -r -s -p 'SMTP-Passwort oder App-Passwort (Eingabe unsichtbar): ' secret </dev/tty || fail 'Passwort-Eingabe abgebrochen.'
    printf '\n'
    [[ -n "$secret" ]] || fail 'Ein leeres Passwort ist nicht erlaubt.'
    if command -v systemd-creds >/dev/null 2>&1; then
        local tmp
        tmp="$(mktemp "$MAIL_PASSWORD_DIR/.smtp-password.cred.XXXXXXXX")"
        if printf '%s' "$secret" | systemd-creds encrypt --name=smtp_password - "$tmp" >/dev/null; then
            chmod 0600 "$tmp"
            chown root:root "$tmp"
            mv -f -- "$tmp" "$MAIL_PASSWORD_ENCRYPTED"
            rm -f -- "$MAIL_PASSWORD_PLAIN"
            echo '  Passwort verschlüsselt unter /etc/netlab-guardian/smtp-password.cred gespeichert.'
        else
            rm -f -- "$tmp"
            unset secret
            fail 'systemd-creds konnte das Passwort nicht verschlüsseln. Keine Klartext-Fallback-Speicherung.'
        fi
    else
        (umask 077; printf '%s' "$secret" > "$MAIL_PASSWORD_PLAIN")
        chmod 0600 "$MAIL_PASSWORD_PLAIN"
        chown root:root "$MAIL_PASSWORD_PLAIN"
        rm -f -- "$MAIL_PASSWORD_ENCRYPTED"
        echo '  WARNUNG: systemd-creds fehlt. Passwort liegt im Klartext in einer root-only-Datei (0600).'
    fi
    unset secret
}

# Einrichtungsdialog: das Passwort wird NIE an PHP oder als Argument übergeben.
# Mail-Konfiguration ist nur für Deploy-Benutzer lesbar, nicht für www-data.
prepare_mail_config() {
    [[ ! -L "$ROOT/config" && ! -L "$ROOT/config/incident_mail.php" ]] \
        || fail 'config/ oder incident_mail.php darf kein Symlink sein.'
    mkdir -p -- "$ROOT/config"
    chown "$APP_USER:$WEB_GROUP" "$ROOT/config"
    chmod 0700 "$ROOT/config"
    local conf="$ROOT/config/incident_mail.php"
    if [[ ! -e "$conf" ]]; then
        (umask 077; as_app cp -- "$ROOT/config/incident_mail.example.php" "$conf")
        echo '  Mail-Konfiguration aus der Vorlage erstellt.'
    fi
    [[ -f "$conf" ]] || fail "Keine reguläre Mail-Konfiguration: $conf"
    chown "$APP_USER:$WEB_GROUP" "$conf"
    chmod 0600 "$conf"
}

configure_mail_interactively() {
    [[ -r /dev/tty && -w /dev/tty ]] || fail 'Mail-Einrichtung braucht ein interaktives Terminal.'
    command -v php >/dev/null 2>&1 || fail 'PHP-CLI wird für die Mail-Einrichtung benötigt.'
    say 'Interaktive SMTP-Einrichtung'
    # Die PNG-Donuts nutzen eine freie Truetype-Schrift für glatte
    # Mittelwerte. Fehlt sie, bleibt ein zeichensatzbasierter Fallback aktiv.
    if [[ ! -r /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf \
       && ! -r /usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf ]] \
       && (( SKIP_PACKAGES == 0 )) && command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y -- fonts-dejavu-core \
            || echo '  WARNUNG: Keine TTF-Schrift vorhanden; Diagrammziffern nutzen Fallback.' >&2
    fi
    prepare_mail_config
    local conf="$ROOT/config/incident_mail.php"
    local sender recipient_string smtp_host smtp_port smtp_encryption smtp_username send_time timezone confirm
    printf '  Deine vorhandene Mail-Konfiguration wird nur nach der letzten Bestätigung ersetzt.\n'
    IFS= read -r -p 'Absender-E-Mail-Adresse: ' sender </dev/tty || fail 'Eingabe abgebrochen.'
    IFS= read -r -p 'Empfänger (mehrere durch Komma trennen): ' recipient_string </dev/tty || fail 'Eingabe abgebrochen.'
    IFS= read -r -p 'SMTP-Server [smtp.office365.com]: ' smtp_host </dev/tty || fail 'Eingabe abgebrochen.'
    smtp_host="${smtp_host:-smtp.office365.com}"
    IFS= read -r -p 'SMTP-Port [587]: ' smtp_port </dev/tty || fail 'Eingabe abgebrochen.'
    smtp_port="${smtp_port:-587}"
    IFS= read -r -p 'Verschlüsselung (starttls/ssl) [starttls]: ' smtp_encryption </dev/tty || fail 'Eingabe abgebrochen.'
    smtp_encryption="${smtp_encryption:-starttls}"
    IFS= read -r -p 'SMTP-Benutzername [gleich wie Absender]: ' smtp_username </dev/tty || fail 'Eingabe abgebrochen.'
    smtp_username="${smtp_username:-$sender}"
    IFS= read -r -p 'Tägliche Versandzeit (HH:MM) [07:00]: ' send_time </dev/tty || fail 'Eingabe abgebrochen.'
    send_time="${send_time:-07:00}"
    IFS= read -r -p 'Zeitzone [Europe/Vienna]: ' timezone </dev/tty || fail 'Eingabe abgebrochen.'
    timezone="${timezone:-Europe/Vienna}"
    # Keine Passwörter in der Konfiguration oder PHP-Prozessliste! Nur harmlose
    # SMTP-Einstellungen werden als Argumente an PHP übergeben.
    local php_source
    php_source='require $argv[1]; require $argv[2];
    $data = ["timezone"=>$argv[4],"send_time"=>$argv[5],
      "smtp_host"=>$argv[6],"smtp_port"=>$argv[7],"smtp_encryption"=>$argv[8],
      "sender"=>$argv[9],"smtp_username"=>$argv[10],
      "recipients"=>array_values(array_filter(array_map("trim",explode(",",$argv[11]))))];
    try { $data = SmtpMailer::validateConfig($data,false); }
    catch (Throwable $e) { fwrite(STDERR, "Ungültige Eingabe: ".$e->getMessage()."\n"); exit(1); }
    $tmp = tempnam(dirname($argv[3]),".incident-mail-");
    if ($tmp === false) { fwrite(STDERR,"Konnte Konfiguration nicht schreiben.\n"); exit(1); }
    chmod($tmp,0600);
    $php = "<?php\ndeclare(strict_types=1);\n\n// SMTP-Zugang: Passwort nur unter /etc/netlab-guardian (systemd).\nreturn ".var_export($data,true).";\n";
    if (file_put_contents($tmp,$php) === false || !rename($tmp,$argv[3])) {
      @unlink($tmp); fwrite(STDERR,"Konnte Konfiguration nicht aktualisieren.\n"); exit(1);
    }'
    if ! as_app php -r "$php_source" "$ROOT/app/Services/IncidentMailSchedule.php" \
        "$ROOT/app/Services/SmtpMailer.php" "$conf" "$timezone" "$send_time" \
        "$smtp_host" "$smtp_port" "$smtp_encryption" "$sender" "$smtp_username" "$recipient_string"; then
        echo '  Keine Einstellungen gespeichert. Bitte --setup-mail erneut ausführen.' >&2
        return 1
    fi
    chown "$APP_USER:$WEB_GROUP" "$conf"
    chmod 0600 "$conf"
    echo '  SMTP-Einstellungen gespeichert. Das Passwort kommt separat unter /etc/netlab-guardian.'
    IFS= read -r -p 'SMTP-Passwort jetzt sicher hinterlegen? [J/n]: ' confirm </dev/tty || fail 'Eingabe abgebrochen.'
    if [[ ! "$confirm" =~ ^[nN]$ ]]; then
        set_mail_password
    else
        echo '  Passwort später setzen: sudo bash install.sh --set-mail-password'
    fi
}

# Mail ist optional; fehlende Credentials blockieren die restliche Installation nicht.
setup_mail_service() {
    say 'Richte täglichen Incident-Mail-Service ein'
    for f in bin/incident_mail.php app/Services/IncidentMailDeviceCheck.php app/Services/IncidentMailSchedule.php app/Services/IncidentMailReport.php app/Services/SmtpMailer.php app/Checks/bootstrap.php python_scripts/main.py config/incident_mail.example.php systemd/netlab-incident-mail.service systemd/netlab-incident-mail-now.service systemd/netlab-incident-mail-test.service systemd/netlab-incident-mail.timer; do
        need_file "$f"
    done
    command -v php >/dev/null 2>&1 || fail 'Für den Mail-Dienst ist PHP-CLI erforderlich.'
    # Auch bei Updates mit --mail-only Outlook-kompatible PNGs ermöglichen.
    if ! php -r 'exit(function_exists("imagecreatetruecolor") ? 0 : 1);' && (( SKIP_PACKAGES == 0 )) && command -v apt-get >/dev/null 2>&1; then
        local gd_version gd_package
        gd_version="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
        gd_package=php-gd
        if command -v apt-cache >/dev/null 2>&1 && apt-cache show "php${gd_version}-gd" >/dev/null 2>&1; then
            gd_package="php${gd_version}-gd"
        fi
        if ! DEBIAN_FRONTEND=noninteractive apt-get install -y -- "$gd_package"; then
            echo '  WARNUNG: PHP-GD fehlt; Diagramme werden durch Kennzahlen und Legenden ersetzt.' >&2
        fi
    fi
    # Die PNG-Donuts nutzen eine freie Truetype-Schrift für glatte
    # Mittelwerte. Fehlt sie, bleibt ein zeichensatzbasierter Fallback aktiv.
    if [[ ! -r /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf \
       && ! -r /usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf ]] \
       && (( SKIP_PACKAGES == 0 )) && command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y -- fonts-dejavu-core \
            || echo '  WARNUNG: Keine TTF-Schrift vorhanden; Diagrammziffern nutzen Fallback.' >&2
    fi
    prepare_mail_config
    local conf="$ROOT/config/incident_mail.php"

    if ! command -v systemctl >/dev/null 2>&1 || [[ ! -d /run/systemd/system ]]; then
        echo '  Hinweis: systemd nicht aktiv; Mail-Service-Dateien vorhanden, Timer manuell installieren.'
        return 0
    fi
    # systemd-Units liegen außerhalb des DocumentRoots; nur der Deploy-Benutzer liest Secrets.
    # Platzhalter werden bei der Installation durch den tatsächlichen Pfad/Benutzer ersetzt.
    [[ "$ROOT" != *'|'* && "$ROOT" != *'&'* && "$ROOT" != *$'\n'* && "$ROOT" != *' '* ]] \
        || fail 'Der systemd-Mail-Service benötigt einen Projektpfad ohne Leerzeichen, |, & oder Zeilenumbruch.'
    local cred_directive=""
    if [[ -f "$MAIL_PASSWORD_ENCRYPTED" && ! -L "$MAIL_PASSWORD_ENCRYPTED" ]]; then
        cred_directive="LoadCredentialEncrypted=smtp_password:$MAIL_PASSWORD_ENCRYPTED"
    elif [[ -f "$MAIL_PASSWORD_PLAIN" && ! -L "$MAIL_PASSWORD_PLAIN" ]]; then
        cred_directive="LoadCredential=smtp_password:$MAIL_PASSWORD_PLAIN"
    fi
    local unit
    for unit in netlab-incident-mail.service netlab-incident-mail-now.service netlab-incident-mail-test.service; do
        sed -e "s|__PROJECT_ROOT__|$ROOT|g" -e "s|__APP_USER__|$APP_USER|g" \
            -e "s|__CREDENTIAL_DIRECTIVE__|$cred_directive|g" \
            "$ROOT/systemd/$unit" > "/etc/systemd/system/$unit"
        chmod 0644 "/etc/systemd/system/$unit"
    done
    # Bis zur validierten Konfiguration keine alte oder Platzhalter-Zeitplanung starten.
    systemctl disable --now netlab-incident-mail.timer >/dev/null 2>&1 || true
    systemctl daemon-reload

    # Beispiel-Credentials dürfen nicht automatisch einen fehlerhaften Timer starten.
    if [[ -z "$cred_directive" ]]; then
        systemctl disable --now netlab-incident-mail.timer >/dev/null 2>&1 || true
        echo '  SMTP-Passwort fehlt. Einmalig einrichten: sudo bash install.sh --set-mail-password'
        return 0
    fi
    # Nur der CLI-Validator entscheidet, ob alle Pflichtfelder sinnvoll gesetzt sind.
    # Eine Musteradresse oder alte Entra-Konfiguration darf den Timer nicht aktivieren.
    if ! as_app php "$ROOT/bin/incident_mail.php" --check >/dev/null; then
        systemctl disable --now netlab-incident-mail.timer >/dev/null 2>&1 || true
        echo "  Mail-Konfiguration ungültig. Korrigiere $conf und führe --mail-only erneut aus." >&2
        return 0
    fi
    # Die Zeit wird einmalig beim Installieren aus der Konfiguration gelesen.
    # systemd startet den Service dann direkt zur passenden Uhrzeit (keine minütlichen PHP-Aufrufe).
    local calendar
    if ! calendar="$(as_app php "$ROOT/bin/incident_mail.php" --print-calendar)"; then
        echo "  Mail-Zeitzone ungültig. Korrigiere $conf und führe --mail-only erneut aus." >&2
        return 0
    fi
    sed -e "s|__ON_CALENDAR__|$calendar|g" \
        "$ROOT/systemd/netlab-incident-mail.timer" > /etc/systemd/system/netlab-incident-mail.timer
    chmod 0644 /etc/systemd/system/netlab-incident-mail.timer
    systemctl daemon-reload
    systemctl enable --now netlab-incident-mail.timer
    systemctl restart netlab-incident-mail.timer
    echo "  Incident-Mail-Timer aktiviert: $calendar (1x täglich; erst Gerätecheck, dann Bericht)."
}

if (( CHECK_ONLY )); then
    check_install
    exit $?
fi
if (( CONFIGURE_MAIL )); then
    configure_mail_interactively
    setup_mail_service
    exit 0
fi
if (( SET_MAIL_PASSWORD )); then
    set_mail_password
    setup_mail_service
    exit 0
fi
if (( MAIL_ONLY )); then
    if [[ ! -f "$ROOT/config/incident_mail.php" && -t 0 && -r /dev/tty ]]; then
        configure_mail_interactively
    fi
    setup_mail_service
    exit 0
fi

if (( TEST_MAIL )); then
    command -v systemctl >/dev/null 2>&1 && [[ -d /run/systemd/system ]] \
        || fail 'Für die geschützte SMTP-Testmail muss systemd aktiv sein.'
    if [[ ! -f /etc/systemd/system/netlab-incident-mail-test.service ]]; then
        echo '  Testmail-Dienst fehlt; installiere zuerst die Mail-Units.'
        setup_mail_service
    fi
    say 'Sende den Incident-Bericht aus der Datenbank sofort (ohne Gerätecheck)'
    if ! systemctl start netlab-incident-mail-test.service; then
        journalctl -u netlab-incident-mail-test.service -n 15 --no-pager --output=cat >&2 || true
        fail 'Manueller Incident-Bericht fehlgeschlagen. Datenbank, SMTP-Einstellungen, Passwort und Anbieterfreigabe prüfen.'
    fi
    journalctl -u netlab-incident-mail-test.service -n 5 --no-pager --output=cat || true
    echo '  Incident-Bericht vom SMTP-Server angenommen. Postfach und ggf. Spam-Ordner prüfen.'
    exit 0
fi

install_packages() {
    (( SKIP_PACKAGES == 0 )) || fail 'Systempakete fehlen. Ohne apt bitte selbst installieren und erneut ausführen.'
    command -v apt-get >/dev/null 2>&1 || fail 'Automatische Systempakete nur auf Debian/Ubuntu (apt-get). Bitte Abhängigkeiten manuell installieren.'
    if (( ${APT_UPDATED:-0} == 0 )); then
        say 'Aktualisiere Paketindex (apt)'
        DEBIAN_FRONTEND=noninteractive apt-get update
        APT_UPDATED=1
    fi
    say "Installiere Systempakete: $*"
    DEBIAN_FRONTEND=noninteractive apt-get install -y -- "$@"
}

say "Projekt: $ROOT | Deploy-Benutzer: $APP_USER | Webserver: $WEB_USER:$WEB_GROUP"
if [[ -f "$ROOT/setup.php" ]]; then
    echo '  Hinweis: veraltete setup.php nach dem Update manuell entfernen.'
fi
if ! command -v php >/dev/null 2>&1; then install_packages php-cli; fi
if ! command -v python3 >/dev/null 2>&1; then install_packages python3; fi
python_ok || fail 'Python muss mindestens 3.10 sein. Bitte eine kompatible Python-Version installieren.'
if ! php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'; then
    fail 'PHP >= 8.1 ist erforderlich.'
fi
if ! php_has_sqlite; then
    version="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
    if command -v apt-cache >/dev/null 2>&1 && apt-cache show "php${version}-sqlite3" >/dev/null 2>&1; then
        install_packages "php${version}-sqlite3"
    else
        install_packages php-sqlite3
    fi
    php_has_sqlite || fail 'pdo_sqlite fehlt weiterhin im CLI-PHP. php --ini / php -m prüfen.'
fi
# Statische PNG-Donuts für Outlook: SVG/CSS-Charts werden von klassischen
# Outlook-Clients nicht zuverlässig dargestellt. PHP-GD wird für PNGs genutzt.
if ! php -r 'exit(function_exists("imagecreatetruecolor") ? 0 : 1);'; then
    if (( SKIP_PACKAGES == 0 )); then
        version="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
        if command -v apt-cache >/dev/null 2>&1 && apt-cache show "php${version}-gd" >/dev/null 2>&1; then
            install_packages "php${version}-gd"
        else
            install_packages php-gd
        fi
    fi
    if ! php -r 'exit(function_exists("imagecreatetruecolor") ? 0 : 1);'; then
        echo '  WARNUNG: PHP-GD nicht verfügbar; HTML-Mail nutzt statt Donut-PNGs Text/Kennzahlen.' >&2
    fi
fi
if command -v dpkg-query >/dev/null 2>&1 && ! dpkg-query -W -f='${Status}' python3-venv 2>/dev/null | grep -q 'install ok installed'; then
    if (( SKIP_PACKAGES == 0 )); then install_packages python3-venv; fi
fi
say 'Richte Verzeichnisse und Gruppenberechtigungen ein'
mkdir -p -- "$STORAGE" "$BACKUPS"
[[ ! -L "$BACKUPS" ]] || fail 'backups/ ist ein Symlink; bitte manuell prüfen.'
chown "$APP_USER:$WEB_GROUP" "$STORAGE"
chmod 2770 "$STORAGE"
chown "$APP_USER:$WEB_GROUP" "$BACKUPS"
chmod 0700 "$BACKUPS"
chgrp "$WEB_GROUP" "$ROOT"
chmod g+rx,g-w,o-w "$ROOT"
for dir in app public python_scripts bin; do
    chgrp -R "$WEB_GROUP" "$ROOT/$dir"
    chmod -R g+rX,g-w "$ROOT/$dir"
done
chown "$APP_USER:$WEB_GROUP" "$ROOT/python_scripts"
chmod u+rwx,g+rx,g-w "$ROOT/python_scripts"
if [[ -d "$VENV" ]]; then
    chown -R "$APP_USER:$WEB_GROUP" "$VENV"
fi
if [[ -e "$DB" ]]; then
    [[ -f "$DB" ]] || fail 'storage/inventory.sqlite ist keine reguläre Datei.'
    chown "$APP_USER:$WEB_GROUP" "$DB"
    chmod 0660 "$DB"
    for extra in "$DB-wal" "$DB-shm" "$DB-journal"; do
        if [[ -f "$extra" && ! -L "$extra" ]]; then
            chown "$APP_USER:$WEB_GROUP" "$extra"
            chmod 0660 "$extra"
        fi
    done
    backup="$(mktemp "$BACKUPS/inventory.sqlite.backup-$(date +%Y%m%d-%H%M%S).XXXXXX")"
    cp -p -- "$DB" "$backup"
    chown "$APP_USER:$WEB_GROUP" "$backup"
    chmod 0600 "$backup"
    echo "  SQLite-Sicherheitskopie: $backup"
fi
if [[ -f "$STORAGE/rack_check.lock" && ! -L "$STORAGE/rack_check.lock" ]]; then
    chown "$APP_USER:$WEB_GROUP" "$STORAGE/rack_check.lock"
    chmod 0660 "$STORAGE/rack_check.lock"
fi
say 'Erstelle/aktualisiere Python-Umgebung'
if [[ ! -x "$VENV_PY" ]]; then
    (umask 022; as_app python3 -m venv "$VENV") || fail 'python3 -m venv fehlgeschlagen. Paket python3-venv prüfen.'
fi
(umask 022; as_app "$VENV_PY" -m pip install --disable-pip-version-check -r "$ROOT/python_scripts/requirements.txt") \
    || fail 'Paketinstallation (pip) fehlgeschlagen. Internet/PyPI und Python-Version prüfen.'
chgrp -R "$WEB_GROUP" "$VENV"
chmod -R g+rX,g-w "$VENV"
as_app "$VENV_PY" -c 'import netmiko; from netmiko.exceptions import NetmikoAuthenticationException, NetmikoTimeoutException' \
    || fail 'Netmiko-Import ist fehlgeschlagen.'
say 'Initialisiere/Migriere SQLite-Datenbank (ohne bestehende Daten zu löschen)'
as_app php -r 'require $argv[1]; new DBInternalAPI();' "$ROOT/db_internal_api.php" \
    || fail 'DB-Initialisierung fehlgeschlagen. Bitte PHP/SQLite-Meldung prüfen.'
chown "$APP_USER:$WEB_GROUP" "$DB"
chmod 0660 "$DB"
if (( RESET_ADMIN )) || ! as_app php -r 'require $argv[1]; $db = new DBInternalAPI(); exit($db->siteUser($argv[2]) === null ? 1 : 0);' "$ROOT/db_internal_api.php" "$ADMIN_USER"; then
    say "Richte Admin-Benutzer '$ADMIN_USER' ein (Passwort wird nicht angezeigt)"
    [[ -t 0 ]] || fail "Für das Admin-Passwort ist ein Terminal erforderlich. Danach manuell: php bin/user.php $ADMIN_USER admin"
    as_app php "$ROOT/bin/user.php" "$ADMIN_USER" admin || fail 'Admin-Einrichtung fehlgeschlagen.'
else
    say "Admin '$ADMIN_USER' existiert bereits – Kennwort bleibt unverändert (--reset-admin zum Ändern)."
fi
check_install || fail 'Mindestens eine Rechte-/Umgebungsprüfung ist fehlgeschlagen.'
if [[ -t 0 && -r /dev/tty && ! -f "$ROOT/config/incident_mail.php" ]]; then
    echo
    echo '  Der SMTP-Assistent kann jetzt Absender, Empfänger, Uhrzeit und Passwort einrichten.'
    mail_answer=''
    IFS= read -r -p 'Mail-Service jetzt einrichten? [J/n]: ' mail_answer </dev/tty || true
    if [[ ! "$mail_answer" =~ ^[nN]$ ]]; then
        configure_mail_interactively
    fi
fi
setup_mail_service
say 'Installation abgeschlossen. DocumentRoot muss auf public/ zeigen.'
echo "Web-Adresse / DocumentRoot: $ROOT/public"
echo 'Bei Apache/PHP-FPM nach geänderter SQLite-PHP-Erweiterung Dienst neu starten.'
