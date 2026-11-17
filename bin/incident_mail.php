<?php
declare(strict_types=1);

// Ausschließlich per PHP-CLI ausführbar: kein Web-/Frontend-Endpunkt.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db_internal_api.php';
require_once __DIR__ . '/../app/Services/IncidentMailSchedule.php';
require_once __DIR__ . '/../app/Services/IncidentMailReport.php';
require_once __DIR__ . '/../app/Services/IncidentMailDeviceCheck.php';
require_once __DIR__ . '/../app/Services/SmtpMailer.php';

const MAIL_CONFIG_PATH = __DIR__ . '/../config/incident_mail.php';

/** Liest das Passwort ausschließlich aus dem von systemd bereitgestellten Credential. */
function loadMailSmtpPassword(): string
{
    $credentialDir = getenv('CREDENTIALS_DIRECTORY');
    if (!is_string($credentialDir) || $credentialDir === '') {
        throw new RuntimeException('SMTP-Passwort ist nur im systemd-Dienst verfügbar. Testmail: sudo bash install.sh --test-mail; Incident-Bericht: sudo systemctl start netlab-incident-mail-now.service');
    }
    $secretPath = rtrim($credentialDir, '/') . '/smtp_password';
    if (!is_file($secretPath) || !is_readable($secretPath)) {
        throw new RuntimeException('Geschütztes SMTP-Passwort fehlt im systemd-Credentials-Verzeichnis.');
    }
    $secret = file_get_contents($secretPath);
    if (!is_string($secret) || trim($secret) === '') {
        throw new RuntimeException('Das geschützte SMTP-Passwort ist leer.');
    }
    return $secret;
}

try {
    $mode = $argv[1] ?? '--scheduled';
    if (!in_array($mode, ['--scheduled', '--dry-run', '--send-now', '--test-mail', '--check', '--print-calendar'], true) || count($argv) > 2) {
        throw new InvalidArgumentException('Aufruf: php bin/incident_mail.php [--scheduled|--dry-run|--send-now|--test-mail|--check|--print-calendar]');
    }
    if (!is_file(MAIL_CONFIG_PATH)) {
        throw new RuntimeException('Mail-Konfiguration fehlt: config/incident_mail.example.php nach config/incident_mail.php kopieren.');
    }
    $config = require MAIL_CONFIG_PATH;
    if (!is_array($config)) {
        throw new RuntimeException('Mail-Konfiguration muss ein PHP-Array zurückgeben.');
    }
    // Passwörter nie in der Projektkonfiguration speichern oder auslesen.
    foreach (['smtp_password', 'client_secret'] as $forbidden) {
        if (array_key_exists($forbidden, $config)) {
            throw new RuntimeException("$forbidden aus config/incident_mail.php entfernen und das SMTP-Passwort mit sudo bash install.sh --set-mail-password geschützt speichern.");
        }
    }
    $config = SmtpMailer::validateConfig($config, false);
    $now = new DateTimeImmutable('now', new DateTimeZone($config['timezone']));

    if ($mode === '--check') {
        echo "Mail-Konfiguration gültig.\n";
        exit(0);
    }
    if ($mode === '--print-calendar') {
        echo IncidentMailSchedule::calendarExpression($config['send_time'], $config['timezone']) . "\n";
        exit(0);
    }
    if ($mode === '--dry-run') {
        $report = IncidentMailReport::fromDatabase(new DBInternalAPI(), $now);
        echo $report['subject'] . "\n\n" . $report['body'];
        exit(0);
    }

    // --test-mail versendet den normalen Bericht auf Basis des gespeicherten
    // Datenbankstands, ohne vorher die Geräte abzufragen oder Incidents zu ändern.
    // Die automatische Tages-Sendemarkierung bleibt ebenfalls unverändert.
    // Das SMTP-Passwort wird erst nach dem Sperren und nur per systemd geladen.

    // Sperre verhindert parallele Timer-/manuelle Sendungen. Der Timer
    // wartet auf einen laufenden manuellen Versand, statt den Tag zu überspringen.
    $lockPath = ProjectPaths::storage() . '/incident_mail.lock';
    $lockHandle = fopen($lockPath, 'c');
    if ($lockHandle === false) {
        throw new RuntimeException('Mail-Sperrdatei kann nicht geöffnet werden. storage/-Rechte prüfen.');
    }
    if (!flock($lockHandle, $mode === '--scheduled' ? LOCK_EX : (LOCK_EX | LOCK_NB))) {
        if ($mode === '--test-mail') {
            throw new RuntimeException('Ein anderer Incident-Mail-Versand läuft bereits. Bitte erneut ausführen.');
        }
        echo "Ein anderer Mail-Versand läuft bereits.\n";
        exit(0);
    }
    $statePath = ProjectPaths::storage() . '/incident_mail_last_sent.txt';
    $lastSent = is_file($statePath) ? trim((string) file_get_contents($statePath)) : null;
    if ($lastSent !== null && $lastSent !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $lastSent)) {
        throw new RuntimeException('Ungültige letzte-Versand-Datei: storage/incident_mail_last_sent.txt');
    }
    if ($mode === '--scheduled' && !IncidentMailSchedule::due($now, $config['send_time'], $lastSent)) {
        exit(0);
    }
    // Schutz vor wiederholten automatischen SMTP-Anmeldeversuchen.
    // Der Zeitstempel wird erst NACH einem vollständig abgeschlossenen
    // Gerätecheck geschrieben: Fehler im Check sind keine SMTP-Versuche.
    $attemptFile = ProjectPaths::storage() . '/incident_mail_last_attempt.txt';
    if ($mode === '--scheduled') {
        $previousAttempt = is_file($attemptFile) ? trim((string) file_get_contents($attemptFile)) : '';
        if ($previousAttempt !== '' && (!ctype_digit($previousAttempt) || strlen($previousAttempt) > 12)) {
            throw new RuntimeException('Ungültiger letzter Mail-Versuch in storage/.');
        }
        if ($previousAttempt !== '' && time() - (int) $previousAttempt < 4 * 3600) {
            exit(0);
        }
    }

    $db = new DBInternalAPI();
    $precheck = null;
    if ($mode !== '--test-mail') {
        // Geplanter Versand (und --send-now): zuerst bekannte Geräte prüfen,
        // dann den aktualisierten Datenbankstand für die Mail verwenden.
        echo "[Incident-Mail] Starte Gerätecheck aller bekannten, freigegebenen Geräte ...\n";
        $precheck = IncidentMailDeviceCheck::run($db, $now, $mode === '--scheduled');
        printf("[Incident-Mail] Gerätecheck abgeschlossen: %d/%d abgefragt, %d ausgeschlossen, %d ohne gültige IP.\n",
            $precheck['attempted'], $precheck['total'], $precheck['excluded'], $precheck['invalid_ip']);
    } else {
        echo "[Incident-Mail] Testversand aus dem gespeicherten Datenbankstand (ohne Gerätecheck).\n";
    }
    // Der Mailinhalt wird erst jetzt aus der Datenbank erstellt. Bei --test-mail
    // werden dabei ausschließlich bereits vorhandene Geräte/Incidents gelesen.
    $reportNow = new DateTimeImmutable('now', new DateTimeZone($config['timezone']));
    $report = IncidentMailReport::fromDatabase($db, $reportNow, $precheck);
    $config['smtp_password'] = loadMailSmtpPassword();
    if ($mode === '--scheduled') {
        if (file_put_contents($attemptFile, (string) time() . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Mail-Versuchsstatus konnte nicht gespeichert werden.');
        }
        chmod($attemptFile, 0600);
    }
    (new SmtpMailer($config))->send($report['subject'], $report['body'], $report['html'], $report['inline_images']);

    // Manuelles Testen sendet den Incident-Bericht aus der bestehenden DB,
    // ohne Gerätechecks, Timer-Ausführung oder Tages-Sendemarkierung zu verändern.
    if ($mode === '--test-mail') {
        printf("SMTP-Server hat den manuell angeforderten Incident-Bericht angenommen: %d offene Incidents auf %d Geräten; %d Empfänger.\n",
            $report['count'], $report['device_count'], count($config['recipients']));
        exit(0);
    }

    // Nur nach erfolgreicher Annahme durch den SMTP-Server persistieren.
    $today = $reportNow->format('Y-m-d');
    $tempPath = tempnam(ProjectPaths::storage(), '.incident-mail-');
    if ($tempPath === false) {
        throw new RuntimeException('Mail-Statusdatei kann nicht erstellt werden.');
    }
    try {
        chmod($tempPath, 0600);
        if (file_put_contents($tempPath, $today . "\n") === false || !rename($tempPath, $statePath)) {
            throw new RuntimeException('Mail-Statusdatei konnte nicht gespeichert werden.');
        }
    } finally {
        if (is_file($tempPath)) {
            unlink($tempPath);
        }
    }
    printf("SMTP-Server hat die Mail angenommen: %d offene Incidents auf %d Geräten (%s).\n",
        $report['count'], $report['device_count'], $today);
} catch (Throwable $error) {
    fwrite(STDERR, '[Incident-Mail] ' . $error->getMessage() . "\n");
    exit(1);
}
