<?php
declare(strict_types=1);

if (loggedIn())
    redirect('index.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $user = strlen($username) <= 40 ? $db->siteUser($username) : null;
    // Gleichmäßige Passwortprüfung auch für unbekannte Benutzer.
    $hash = $user['password_hash'] ?? '$2y$10$9wci0zqHkb6Cn/H5vuutNuSIwOV/FHD1P4TItlGu/TaE5PuR6Cm0m';
    if ($user && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['user'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        redirect('index.php');
    }
    $error = 'Benutzername oder Passwort ist falsch.';
}
