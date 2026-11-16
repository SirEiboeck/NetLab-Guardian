<?php require __DIR__ . '/../layout/top.php'; ?>
<main class="workspace admin-workspace">
        <div class="section-top">
            <div>
                <div class="eyebrow muted">ADMINISTRATOREN</div>
                <h1>Website-Benutzer</h1>
                <p class="subtle">Konten anlegen, Rollen vergeben, Passwörter zurücksetzen und Benutzer löschen.</p>
            </div>
            <a class="button light" href="site_users.php?edit=new#editor">Benutzer hinzufügen</a>
        </div>
        <p class="admin-note">Angemeldet als <?= h($_SESSION['user']) ?>. Nur Administratoren haben Zugriff.</p>
        <?php if ($flash): ?><p class="notice success" role="status"><?= h($flash) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>

        <?php if ($showEditor): ?>
            <section class="panel editor-panel" id="editor">
                <div class="section-top">
                    <h2><?= $editing ? 'Benutzer bearbeiten' : 'Benutzer hinzufügen' ?></h2>
                    <a class="text-button" href="site_users.php">Schließen ×</a>
                </div>
                <form class="device-form" method="post" action="site_users.php?edit=<?= rawurlencode($editName) ?>#editor">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="original_username" value="<?= h($editing['username'] ?? '') ?>">
                    <div class="form-row">
                        <label>Benutzername *
                            <input name="username" required minlength="3" maxlength="40"
                                pattern="[a-zA-Z0-9_.-]{3,40}" autocomplete="off"
                                <?= $editing ? 'readonly' : '' ?> value="<?= h($formName) ?>">
                        </label>
                        <label>Rolle *
                            <select name="role" required>
                                <option value="viewer" <?= $formRole === 'viewer' ? 'selected' : '' ?>>Viewer (nur lesen)</option>
                                <option value="admin" <?= $formRole === 'admin' ? 'selected' : '' ?>>Administrator</option>
                            </select>
                        </label>
                    </div>
                    <label><?= $editing ? 'Neues Passwort (leer lassen = unverändert)' : 'Passwort *' ?>
                        <input name="password" type="password" autocomplete="new-password"
                            minlength="8" maxlength="1024" <?= $editing ? '' : 'required' ?>>
                    </label>
                    <p class="subtle">Mindestens 8 Zeichen. Passwörter werden nur als sicherer Hash gespeichert.
                        <?= $editing ? 'Der Benutzername ist nach dem Anlegen nicht änderbar.' : '' ?></p>
                    <div class="dialog-actions">
                        <a href="site_users.php" class="button outline">Abbrechen</a>
                        <button class="button light" type="submit">Speichern</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($deleting): ?>
            <section class="panel" id="delete">
                <h2>Benutzer wirklich löschen?</h2>
                <p>Benutzer <strong><?= h($deleting['username']) ?></strong> (<?= h($deleting['role']) ?>) wird dauerhaft entfernt.</p>
                <form method="post" action="site_users.php?delete=<?= rawurlencode($deleting['username']) ?>#delete">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="username" value="<?= h($deleting['username']) ?>">
                    <div class="dialog-actions">
                        <a href="site_users.php" class="button outline">Abbrechen</a>
                        <button type="submit" class="button danger">Endgültig löschen</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <div class="table-scroll admin-table-wrap">
            <table>
                <thead><tr><th>Benutzername</th><th>Rolle</th><th>Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= h($user['username']) ?><?= $user['username'] === $_SESSION['user'] ? ' (Du)' : '' ?></td>
                        <td><?= $user['role'] === 'admin' ? 'Administrator' : 'Viewer' ?></td>
                        <td>
                            <a class="text-button" href="site_users.php?edit=<?= rawurlencode($user['username']) ?>#editor">Bearbeiten</a>
                            <?php if ($user['username'] !== $_SESSION['user']): ?>
                                <a class="text-button delete-button" href="site_users.php?delete=<?= rawurlencode($user['username']) ?>#delete">Löschen</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="3">Keine Benutzer vorhanden.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
<?php require __DIR__ . '/../layout/bottom.php'; ?>
