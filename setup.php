<?php
declare(strict_types=1);

// Ausschließlich über die Konsole auf dem Server ausführen.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/db_internal_api.php';

$username = trim((string) ($argv[1] ?? ''));
$role = (string) ($argv[2] ?? 'admin');
if ($username === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $username) || !in_array($role, ['admin', 'viewer'], true)) {
    fwrite(STDERR, "Aufruf: php setup.php BENUTZERNAME [admin|viewer]\n");
    exit(1);
}

echo "Neues Passwort für {$username} ({$role}): ";
if (function_exists('shell_exec') && DIRECTORY_SEPARATOR === '/')
    @shell_exec('stty -echo');
$password = rtrim((string) fgets(STDIN), "\r\n");
if (function_exists('shell_exec') && DIRECTORY_SEPARATOR === '/')
    @shell_exec('stty echo');
echo "\n";
if (strlen($password) <= 5) {
    fwrite(STDERR, "Mindestens 5 Zeichen erforderlich.\n");
    exit(1);
}
$db = new DBInternalAPI();
$db->setSiteUser($username, password_hash($password, PASSWORD_DEFAULT), $role);
echo "Benutzer wurde angelegt/aktualisiert.\n";
