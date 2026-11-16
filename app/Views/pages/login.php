<?php require __DIR__ . '/../layout/auth_top.php'; ?>
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
<?php require __DIR__ . '/../layout/bottom.php'; ?>
