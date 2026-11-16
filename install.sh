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
  --help                   Hilfe anzeigen

Beispiel:
  sudo ./install.sh --web-user www-data --app-user user --admin admin

Der Installer erstellt python_scripts/.venv und prüft PHP-PDO-SQLite,
Rechte sowie den Start des Python-Interpreters durch den Webserverbenutzer.
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

for f in schema.sql setup.php db_internal_api.php app/Database/db_internal_api.php app/Support/ProjectPaths.php public/index.php python_scripts/main.py python_scripts/requirements.txt; do
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

if (( CHECK_ONLY )); then
    check_install
    exit $?
fi

install_packages() {
    (( SKIP_PACKAGES == 0 )) || fail "Systempakete fehlen. Ohne apt bitte selbst installieren und erneut ausführen."
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
if ! command -v php >/dev/null 2>&1; then install_packages php-cli; fi
if ! command -v python3 >/dev/null 2>&1; then install_packages python3; fi
python_ok || fail 'Python muss mindestens 3.10 sein. Bitte eine kompatible Python-Version installieren.'
if ! php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'; then
    fail 'PHP >= 8.1 ist erforderlich.'
fi
if ! php_has_sqlite; then
    version="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
    # Die versionsspezifische Erweiterung verhindert Fehlkonfiguration bei parallel installierten PHP-Versionen.
    if command -v apt-cache >/dev/null 2>&1 && apt-cache show "php${version}-sqlite3" >/dev/null 2>&1; then
        install_packages "php${version}-sqlite3"
    else
        install_packages php-sqlite3
    fi
    php_has_sqlite || fail 'pdo_sqlite fehlt weiterhin im CLI-PHP. php --ini / php -m prüfen.'
fi
# Bei Debian/Ubuntu ist das Modul venv oft von python3 getrennt.
if command -v dpkg-query >/dev/null 2>&1 && ! dpkg-query -W -f='${Status}' python3-venv 2>/dev/null | grep -q 'install ok installed'; then
    if (( SKIP_PACKAGES == 0 )); then install_packages python3-venv; fi
fi

say 'Richte Verzeichnisse und Gruppenberechtigungen ein'
mkdir -p -- "$STORAGE" "$BACKUPS"
[[ ! -L "$BACKUPS" ]] || fail 'backups/ ist ein Symlink; bitte manuell prüfen.'
chown "$APP_USER:$WEB_GROUP" "$STORAGE"
chmod 2770 "$STORAGE"
# Backups sind nicht durch den Webserver lösch-/lesbar.
chown "$APP_USER:$WEB_GROUP" "$BACKUPS"
chmod 0700 "$BACKUPS"
# Nur Codegruppen lesbar machen; Webserver soll PHP-Dateien niemals schreiben dürfen.
chgrp "$WEB_GROUP" "$ROOT"
chmod g+rx,g-w,o-w "$ROOT"
for dir in app public python_scripts; do
    chgrp -R "$WEB_GROUP" "$ROOT/$dir"
    chmod -R g+rX,g-w "$ROOT/$dir"
done
# Der Deploy-Benutzer muss python_scripts/.venv erzeugen und aktualisieren können.
chown "$APP_USER:$WEB_GROUP" "$ROOT/python_scripts"
chmod u+rwx,g+rx,g-w "$ROOT/python_scripts"
if [[ -d "$VENV" ]]; then
    chown -R "$APP_USER:$WEB_GROUP" "$VENV"
fi
# Repariert eine zuvor als 0600 von root/www-data angelegte SQLite-Datei.
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
    # Eine Kopie zur Orientierung; für konsistente Backups bei laufender Website vorher stoppen.
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
    # 022 für Webserver-lesbare venv; keine root-eigene Python-Installation.
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
    [[ -t 0 ]] || fail "Für das Admin-Passwort ist ein Terminal erforderlich. Danach manuell: php setup.php $ADMIN_USER admin"
    as_app php "$ROOT/setup.php" "$ADMIN_USER" admin || fail 'Admin-Einrichtung fehlgeschlagen.'
else
    say "Admin '$ADMIN_USER' existiert bereits – Kennwort bleibt unverändert (--reset-admin zum Ändern)."
fi

check_install || fail 'Mindestens eine Rechte-/Umgebungsprüfung ist fehlgeschlagen.'
say 'Installation abgeschlossen. DocumentRoot muss auf public/ zeigen.'
echo "Web-Adresse / DocumentRoot: $ROOT/public"
echo 'Bei Apache/PHP-FPM nach geänderter SQLite-PHP-Erweiterung Dienst neu starten.'
