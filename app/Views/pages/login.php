<?php require __DIR__ . '/../layout/auth_top.php'; ?>
<main class="login-shell">
    <section class="login-card" aria-labelledby="loginTitle">
        <div class="login-brand-header">
            <span class="logo-symbol" aria-hidden="true">N</span>
            <div class="login-brand-name">NetLab <strong>Guardian</strong><small>Netzwerkverwaltung</small></div>
        </div>
        <h1 id="loginTitle">Anmelden</h1>
        <?php if ($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>
        <form method="post" class="device-form site-login-form">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
            <label for="username">Benutzername<input id="username" name="username" autocomplete="username" required autofocus maxlength="40" value="<?= h($_POST['username'] ?? '') ?>"></label>
            <label for="password">Passwort<input id="password" name="password" type="password" autocomplete="current-password" required></label>
            <button class="button light login-submit" type="submit">Anmelden</button>
        </form>
    </section>
</main>
<?php require __DIR__ . '/../layout/bottom.php'; ?>
