<?php
declare(strict_types=1);

/**
 * Sicherer Start der Anwendung. Details gehen ausschließlich ins PHP-Fehlerlog,
 * niemals ungefiltert an Besucher der Website.
 */
function netlabStartupFailure(string $message, ?Throwable $cause = null): never
{
    error_log('[NetLab Guardian] Start fehlgeschlagen: ' . $message . ($cause !== null ? ' | ' . (string) $cause : ''));
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException($message, 0, $cause);
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>NetLab Guardian – Startproblem</title>'
       . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#111923;color:#e7edf5;font:16px system-ui,sans-serif}'
       . 'main{max-width:620px;margin:24px;padding:32px;border:1px solid #354354;border-radius:12px;background:#1b2531}'
       . 'h1{font-size:24px;margin-top:0}p{line-height:1.6}small{color:#aebdce}</style>'
       . '<main><h1>NetLab Guardian konnte nicht starten</h1><p>' . $safe . '</p>'
       . '<small>Weitere technische Informationen stehen im PHP- bzw. Webserver-Fehlerlog.</small></main></html>';
    exit;
}

$requiredFiles = [
    'db_internal_api.php',
    'app/Support/ProjectPaths.php',
    'app/Support/helpers.php',
    'app/Database/db_internal_api.php',
    'app/Checks/bootstrap.php',
    'app/Checks/Configuration.php',
    'app/Checks/Execution.php',
    'app/Checks/IncidentResults.php',
    'app/Views/dashboard.php',
    'app/Controllers/dashboard.php',
];
foreach ($requiredFiles as $relativeFile) {
    if (!is_file(__DIR__ . '/' . $relativeFile)) {
        netlabStartupFailure('Benötigte Datei fehlt: ' . $relativeFile . '. Bitte die vollständige ZIP im Projektstamm entpacken und auf Groß-/Kleinschreibung achten.');
    }
}
if (!extension_loaded('pdo_sqlite')) {
    netlabStartupFailure('Die PHP-Erweiterung pdo_sqlite ist nicht aktiviert. Bitte die SQLite-Unterstützung der Webserver-PHP-Version aktivieren.');
}
if (!is_dir(__DIR__ . '/storage') || !is_writable(__DIR__ . '/storage')) {
    netlabStartupFailure('Das Verzeichnis storage/ fehlt oder ist für den PHP-Webserverbenutzer nicht beschreibbar.');
}
$sqliteFile = __DIR__ . '/storage/inventory.sqlite';
if (is_file($sqliteFile) && (!is_readable($sqliteFile) || !is_writable($sqliteFile))) {
    netlabStartupFailure('Die SQLite-Datei storage/inventory.sqlite ist für PHP nicht les- oder beschreibbar. Den CLI-Installer sudo bash install.sh auf dem Server ausführen.');
}

try {
    require_once __DIR__ . '/db_internal_api.php';
    $db = new DBInternalAPI();
} catch (Throwable $error) {
    netlabStartupFailure('Die Datenbank konnte nicht geöffnet oder initialisiert werden. Bitte Dateiberechtigungen und PHP-Fehlerlog prüfen.', $error);
}

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; script-src 'none'; object-src 'none'");
    header('Cache-Control: no-store, private');
    session_name('netlab_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if (session_start() !== true) {
        netlabStartupFailure('Die Anmeldungssitzung kann nicht gestartet werden. Bitte die PHP-Session-Konfiguration prüfen.');
    }
}

require_once __DIR__ . '/app/Support/helpers.php';
