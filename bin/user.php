<?php
declare(strict_types=1);

// CLI-only: Benutzeranlage und Passwortänderung; niemals über den Browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/db_internal_api.php';

$username = trim((string) ($argv[1] ?? ''));
$role = (string) ($argv[2] ?? 'admin');
if ($username === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $username) || !in_array($role, ['admin', 'viewer'], true)) {
    fwrite(STDERR, "Aufruf: php bin/user.php BENUTZERNAME [admin|viewer]\n");
    exit(1);
}

if (function_exists('stream_isatty') && !stream_isatty(STDIN)) {
    fwrite(STDERR, "Passwort nur interaktiv über ein Terminal eingeben (nicht als Parameter).\n");
    exit(1);
}

echo "Neues Passwort für {$username} ({$role}): ";
$hideInput = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')
    && function_exists('stream_isatty') && stream_isatty(STDIN);
if ($hideInput) {
    @shell_exec('stty -echo 2>/dev/null');
}
try {
    $password = fgets(STDIN);
} finally {
    if ($hideInput) {
        @shell_exec('stty echo 2>/dev/null');
    }
    echo "\n";
}
if ($password === false || strlen(rtrim($password, "\r\n")) < 8) {
    fwrite(STDERR, "Passwort muss mindestens 8 Zeichen haben.\n");
    exit(1);
}
$password = rtrim($password, "\r\n");
try {
    $db = new DBInternalAPI();
    $db->setSiteUser($username, password_hash($password, PASSWORD_DEFAULT), $role);
    echo "Benutzer wurde angelegt/aktualisiert.\n";
} catch (PDOException $error) {
    fwrite(STDERR, "Datenbankfehler: " . $error->getMessage() . "\n"
        . "Prüfe storage/inventory.sqlite und Rechte oder führe sudo ./install.sh aus.\n");
    exit(1);
}
