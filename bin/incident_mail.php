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

    // --test-mail verwendet denselben Bericht wie der automatische Versand.
    // Nur die automatische Tages-Sendemarkierung bleibt dabei unverändert.
    // Das SMTP-Passwort wird erst nach dem Sperren und nur per systemd geladen.

    // Sperre verhindert parallele Timer-/manuelle Sendungen.
    $lockPath = ProjectPaths::storage() . '/incident_mail.lock';
    $lockHandle = fopen($lockPath, 'c');
    if ($lockHandle === false) {
        throw new RuntimeException('Mail-Sperrdatei kann nicht geöffnet werden. storage/-Rechte prüfen.');
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
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
    // Bei gesperrtem SMTP-Login nicht minütlich neue Anmeldungen versuchen
    // (sonst drohen Konto-Sperren). Automatische Fehlversuche maximal alle 4 Stunden.
    if ($mode === '--scheduled') {
        $attemptFile = ProjectPaths::storage() . '/incident_mail_last_attempt.txt';
        $previousAttempt = is_file($attemptFile) ? trim((string) file_get_contents($attemptFile)) : '';
        if ($previousAttempt !== '' && (!ctype_digit($previousAttempt) || strlen($previousAttempt) > 12)) {
            throw new RuntimeException('Ungültiger letzter Mail-Versuch in storage/.');
        }
        if ($previousAttempt !== '' && time() - (int) $previousAttempt < 4 * 3600) {
            exit(0);
        }
        if (file_put_contents($attemptFile, (string) time() . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Mail-Versuchsstatus konnte nicht gespeichert werden.');
        }
        chmod($attemptFile, 0600);
    }

    $config['smtp_password'] = loadMailSmtpPassword();
    $report = IncidentMailReport::fromDatabase(new DBInternalAPI(), $now);
    (new SmtpMailer($config))->send($report['subject'], $report['body']);

    // Manuelles Testen sendet den vollständigen tagesaktuellen Incident-Bericht,
    // beeinflusst aber weder die spätere Timer-Ausführung noch die Tages-Sendemarkierung.
    if ($mode === '--test-mail') {
        printf("SMTP-Server hat den manuell angeforderten Incident-Bericht angenommen: %d offene Incidents auf %d Geräten; %d Empfänger.\n",
            $report['count'], $report['device_count'], count($config['recipients']));
        exit(0);
    }

    // Nur nach erfolgreicher Annahme durch den SMTP-Server persistieren.
    $today = $now->format('Y-m-d');
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
