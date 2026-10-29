<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
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
?>
<!doctype html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#02070b">
    <title>Anmelden — NetLab Guardian</title>
    <link rel="stylesheet" href="style.css">
</head>

<body class="login-page">
    <main class="login-shell">
        <section class="login-visual" aria-hidden="true">
            <div class="login-visual-overlay"></div>
            <div class="login-visual-copy"><span class="eyebrow">Netzwerkverwaltung</span>
                <h1>NetLab<br>Guardian</h1>
                <p>Laborgeräte zentral verwalten.</p>
            </div>
        </section>
        <section class="login-card" aria-labelledby="loginTitle">
            <div class="login-brand brand">NetLab Guardian</div>
            <div>
                <div class="eyebrow muted">GESCHÜTZTER BEREICH</div>
                <h2 id="loginTitle">Anmelden</h2>
                <p class="login-intro">Melde dich an, um die Netzwerkübersicht aufzurufen.</p>
            </div>
            <form method="post" class="device-form site-login-form"><input type="hidden" name="csrf"
                    value="<?= h(csrf()) ?>">
                <label for="username">Benutzername<input id="username" name="username" autocomplete="username" required
                        autofocus maxlength="40" value="<?= h($_POST['username'] ?? '') ?>"></label>
                <label for="password">Passwort<input id="password" name="password" type="password"
                        autocomplete="current-password" required></label>
                <p class="form-error login-error" role="alert"><?= h($error) ?></p><button
                    class="button light login-submit" type="submit">Anmelden</button>
            </form>
            <p class="login-footnote">NetLab Guardian · Labor-Netzwerk</p>
        </section>
    </main>
</body>

</html>