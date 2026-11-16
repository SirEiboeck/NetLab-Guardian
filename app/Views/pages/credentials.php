<?php require __DIR__ . '/../layout/top.php'; ?>
<main class="workspace admin-workspace">
        <div class="section-top">
            <div>
                <div class="eyebrow muted">NETZWERKGERÄTE</div>
                <h1>Credentials verwalten</h1>
                <p class="subtle">Zugangsdaten für den Rack-Check und die automatisch erkannten Geräte.</p>
            </div>
            <a class="button light" href="credentials.php?edit=new#editor">Credential hinzufügen</a>
        </div>
        <p class="admin-note">Benutzernamen und Passwörter werden auf dieser Admin-Seite im Klartext angezeigt und in SQLite unter <code>credential</code> unverschlüsselt gespeichert. Website-Logins sind davon unabhängig.</p>
        <?php if ($flash): ?><p class="notice success" role="status"><?= h($flash) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>

        <?php if ($showEditor): ?>
            <section class="panel editor-panel" id="editor">
                <div class="section-top">
                    <h2><?= $editing ? 'Credential bearbeiten' : 'Credential hinzufügen' ?></h2>
                    <a class="text-button" href="credentials.php">Schließen ×</a>
                </div>
                <form class="device-form" method="post" action="credentials.php<?= $editing ? '?edit=' . (int) $editing['id'] : '?edit=new' ?>#editor" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                    <div class="form-row">
                        <label>Benutzername *
                            <input name="username" maxlength="128" required autocomplete="off" value="<?= h($formUsername) ?>">
                        </label>
                        <label>Passwort (Klartext)
                            <input name="password" type="text" maxlength="1024" autocomplete="off" spellcheck="false" value="<?= h($formPassword) ?>">
                        </label>
                    </div>
                    <p class="subtle">Leeres Passwort ist erlaubt. Ändern eines Credentials aktualisiert die Zugangsdaten für alle Geräte, denen es zugeordnet ist.</p>
                    <div class="dialog-actions">
                        <a href="credentials.php" class="button outline">Abbrechen</a>
                        <button class="button light" type="submit">Speichern</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($deleting): ?>
            <section class="panel" id="delete">
                <h2>Credential wirklich löschen?</h2>
                <p>Credential <strong><?= h($deleting['username']) ?> (#<?= (int) $deleting['id'] ?>)</strong> wird dauerhaft gelöscht. Die Zuordnung zu <?= (int) ($assignedCount[$deleteId] ?? 0) ?> Geräten wird ebenfalls entfernt.</p>
                <form method="post" action="credentials.php?delete=<?= $deleteId ?>#delete">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $deleteId ?>">
                    <div class="dialog-actions">
                        <a class="button outline" href="credentials.php">Abbrechen</a>
                        <button class="button danger" type="submit">Endgültig löschen</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel" aria-label="Gespeicherte Credentials">
            <div class="section-top"><h2>Gespeicherte Zugangsdaten <span class="pill neutral-pill"><?= count($credentials) ?></span></h2></div>
            <div class="table-scroll admin-table-wrap">
                <table>
                    <thead><tr><th>ID</th><th>Benutzername</th><th>Passwort (Klartext)</th><th>Explizite Gerätezuordnungen</th><th>Aktionen</th></tr></thead>
                    <tbody>
                        <?php foreach ($credentials as $credential): ?>
                            <tr>
                                <td><?= (int) $credential['id'] ?></td>
                                <td><?= h($credential['username']) ?></td>
                                <td><code class="credential-secret"><?= h($credential['pwd']) ?></code></td>
                                <td><?= (int) ($assignedCount[(int) $credential['id']] ?? 0) ?></td>
                                <td><div class="action-cell">
                                    <a class="text-button" href="credentials.php?edit=<?= (int) $credential['id'] ?>#editor">Bearbeiten</a>
                                    <a class="text-button delete-button" href="credentials.php?delete=<?= (int) $credential['id'] ?>#delete">Löschen</a>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$credentials): ?><tr><td colspan="5">Noch keine Credentials vorhanden. Bitte zuerst Zugangsdaten hinzufügen.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="subtle">Die Anzahl rechts zählt nur explizite Zuordnungen. Ohne eine Zuordnung stehen die Credentials automatisch allen Geräten zur Verfügung.</p>
        </section>

        <section class="panel" id="assignment">
            <h2>Credentials einem Gerät zuordnen</h2>
            <p class="subtle">Wähle ein Gerät aus. Sobald mindestens ein Credential angehakt ist, wird für dieses Gerät beim Rack-Check <strong>nur diese Auswahl</strong> verwendet. Ohne Häkchen gelten wieder alle global gespeicherten Credentials. Neu entdeckte Geräte verwenden zunächst ebenfalls alle globalen Credentials.</p>
            <form method="get" action="credentials.php#assignment" class="device-form">
                <label for="selected-device">Gerät
                    <select name="device" id="selected-device" required>
                        <option value="">Gerät auswählen …</option>
                        <?php foreach ($devices as $device): ?>
                            <option value="<?= (int) $device['id'] ?>" <?= $selectedDeviceId === (int) $device['id'] ? 'selected' : '' ?>><?= h(($device['hostname'] ?: 'Gerät ' . $device['id']) . ' · ' . ($device['management_ip'] ?: 'ohne IP') . ' · ' . ($device['rack_position'] ?: 'ohne Rack')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="dialog-actions"><button class="button outline" type="submit">Gerät laden</button></div>
            </form>
            <?php if ($selectedDeviceId): ?>
                <form class="device-form" method="post" action="credentials.php?device=<?= $selectedDeviceId ?>#assignment">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="device_id" value="<?= $selectedDeviceId ?>">
                    <h3><?= h($devicesById[$selectedDeviceId]['hostname'] ?: 'Gerät ' . $selectedDeviceId) ?></h3>
                    <div class="credential-choices">
                        <?php foreach ($credentials as $credential): ?>
                            <label class="credential-choice">
                                <input type="checkbox" name="credential_ids[]" value="<?= (int) $credential['id'] ?>" <?= isset($selectedCredentials[(int) $credential['id']]) ? 'checked' : '' ?>>
                                <span><?= h($credential['username']) ?> <small>#<?= (int) $credential['id'] ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$credentials): ?><p class="subtle">Zuerst mindestens ein Credential hinzufügen.</p><?php endif; ?>
                    <p class="subtle">Aktuell: <?= $selectedCredentials ? count($selectedCredentials) . ' ausgewählte Credentials' : 'alle globalen Credentials (keine gesonderte Zuordnung)' ?>.</p>
                    <div class="dialog-actions"><button type="submit" class="button light">Zuordnung speichern</button></div>
                </form>
            <?php endif; ?>
        </section>
    </main>
<?php require __DIR__ . '/../layout/bottom.php'; ?>
