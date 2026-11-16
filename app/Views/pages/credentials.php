<?php require __DIR__ . '/../layout/top.php'; ?>
<main class="workspace admin-workspace credentials-workspace">
    <div class="section-top page-title-row">
        <h1>Zugangsdaten</h1>
        <a class="button light" href="credentials.php?edit=new#editor">Hinzufügen</a>
    </div>
    <?php if ($flash): ?><p class="notice success" role="status"><?= h($flash) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?php if ($showEditor): ?>
    <section class="panel editor-panel" id="editor">
        <div class="section-top"><h2><?= $editing ? 'Zugangsdaten bearbeiten' : 'Zugangsdaten hinzufügen' ?></h2><a class="text-button" href="credentials.php">Schließen</a></div>
        <form class="device-form" method="post" action="credentials.php<?= $editing ? '?edit=' . (int) $editing['id'] : '?edit=new' ?>#editor" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <div class="form-row">
                <label>Benutzername<input name="username" maxlength="128" required value="<?= h($formUsername) ?>" autocomplete="off"></label>
                <label>Passwort<input name="password" type="password" maxlength="1024" autocomplete="new-password" placeholder="<?= $editing ? 'Unverändert lassen' : 'Passwort eingeben' ?>"></label>
            </div>
            <div class="dialog-actions"><a class="button outline" href="credentials.php">Abbrechen</a><button class="button light" type="submit">Speichern</button></div>
        </form>
    </section>
    <?php endif; ?>
    <?php if ($deleting): ?>
    <section class="panel" id="delete">
        <h2>Zugangsdaten löschen?</h2>
        <p><strong><?= h($deleting['username']) ?></strong></p>
        <form method="post" action="credentials.php?delete=<?= $deleteId ?>#delete">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $deleteId ?>">
            <div class="dialog-actions"><a class="button outline" href="credentials.php">Abbrechen</a><button class="button danger" type="submit">Löschen</button></div>
        </form>
    </section>
    <?php endif; ?>
    <section class="panel">
        <div class="section-top"><h2>Gespeicherte Zugänge <span class="pill neutral-pill"><?= count($credentials) ?></span></h2></div>
        <div class="table-scroll admin-table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Benutzername</th><th scope="col">Passwort</th><th scope="col">Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($credentials as $credential): ?>
                <tr>
                    <td><strong><?= h($credential['username']) ?></strong></td>
                    <td><span class="table-muted">Gespeichert</span></td>
                    <td><div class="action-cell"><a class="text-button" href="credentials.php?edit=<?= (int) $credential['id'] ?>#editor">Bearbeiten</a><a class="text-button delete-button" href="credentials.php?delete=<?= (int) $credential['id'] ?>#delete">Löschen</a></div></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$credentials): ?><tr><td colspan="3">Keine Zugangsdaten vorhanden.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require __DIR__ . '/../layout/bottom.php'; ?>
