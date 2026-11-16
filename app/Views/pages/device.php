<?php require __DIR__ . '/../layout/top.php'; ?>
<?php
[$oldRack, $oldUnit] = rackScanPosition($edit['rack_position'] ?? null);
$rackSelected = array_key_exists('rack', $edit) ? (string) $edit['rack'] : (string) ($oldRack ?? '');
$unitSelected = array_key_exists('unit', $edit) ? (string) $edit['unit'] : (string) ($oldUnit ?? '');
$editing = $isNew || ($error !== '' && (string) ($_POST['action'] ?? '') === 'save_device') || (string) ($_GET['edit'] ?? '') === '1';
$values = [
    'Hostname' => ['hostname', 80],
    'Gerätetyp' => ['device_type', 80],
    'Modell' => ['model', 120],
    'Management-IP' => ['management_ip', 45],
    'Seriennummer' => ['serial_number', 120],
    'Firmware' => ['firmware', 120],
    'Konsolenport' => ['console_port', 120],
    'Inventarnummer' => ['inventory_number', 120],
];
?>
<main class="workspace detail-workspace">
    <div class="section-top page-title-row">
        <div>
            <a class="back-link" href="details.php#inventory">← Geräte</a>
            <h1><?= $isNew ? 'Gerät hinzufügen' : h($device['hostname'] ?: 'Gerät ' . $id) ?></h1>
        </div>
        <div class="device-title-actions">
            <?php if (!$isNew): ?><span class="pill <?= pillClass($status) ?>"><?= h(statusLabel($status)) ?></span><?php endif; ?>
            <?php if (isAdmin() && !$isNew): ?>
                <a class="button outline device-edit-toggle" href="device.php?id=<?= $id ?><?= $editing ? '' : '&amp;edit=1' ?>"
                   data-device-edit-toggle aria-controls="device-data-form" aria-expanded="<?= $editing ? 'true' : 'false' ?>">
                    <?= $editing ? 'Bearbeitung beenden' : 'Bearbeiten' ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($flash): ?><p class="notice success" role="status"><?= h($flash) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>

    <section class="panel device-detail-panel" aria-labelledby="device-info-heading">
        <div class="section-top"><h2 id="device-info-heading">Gerätedaten</h2></div>
        <?php if (isAdmin()): ?>
        <form id="device-data-form" class="device-form <?= $editing ? 'is-editing' : '' ?>"
              method="post" action="<?= $isNew ? 'device.php' : 'device.php?id=' . $id ?>" data-device-editor>
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
            <input type="hidden" name="action" value="save_device">
        <?php endif; ?>
        <div class="table-scroll">
            <table class="device-attribute-table editable-device-table">
                <colgroup><col class="attribute-label-column"><col></colgroup>
                <tbody>
                <?php foreach ($values as $label => [$field, $max]):
                    $value = (string) ($edit[$field] ?? '');
                    $required = in_array($field, ['hostname', 'device_type'], true);
                ?>
                    <tr><th scope="row"><?= h($label) ?></th><td>
                        <div class="attribute-content" title="<?= h($value) ?>">
                            <span class="device-read-value"><?= h($value !== '' ? $value : '—') ?></span>
                            <?php if (isAdmin()): ?><span class="device-editor">
                                <input id="field-<?= h($field) ?>" name="<?= h($field) ?>" maxlength="<?= $max ?>"
                                       aria-label="<?= h($label) ?>" value="<?= h($value) ?>"
                                       <?= $required ? 'required' : '' ?> <?= !$editing ? 'disabled' : '' ?>>
                            </span><?php endif; ?>
                        </div>
                    </td></tr>
                <?php endforeach; ?>
                <tr><th scope="row">Rack / HE</th><td>
                    <div class="attribute-content" title="<?= h((string) ($edit['rack_position'] ?? '')) ?>">
                        <span class="device-read-value"><?= h(($edit['rack_position'] ?? '') ?: '—') ?></span>
                        <?php if (isAdmin()): ?><span class="device-editor device-position-fields">
                            <label><span class="visually-hidden">Rack</span><select name="rack" aria-label="Rack" <?= !$editing ? 'disabled' : '' ?>><option value="">Kein Rack</option>
                                <?php foreach ([1, 2, 3] as $rack): ?><option value="<?= $rack ?>" <?= $rackSelected === (string) $rack ? 'selected' : '' ?>>Rack <?= $rack ?></option><?php endforeach; ?>
                            </select></label>
                            <label><span class="visually-hidden">Höheneinheit</span><select name="unit" aria-label="Höheneinheit" <?= !$editing ? 'disabled' : '' ?>><option value="">Ohne HE</option>
                                <?php for ($unit = 1; $unit <= 30; $unit++): ?><option value="<?= $unit ?>" <?= $unitSelected === (string) $unit ? 'selected' : '' ?>>HE <?= $unit ?></option><?php endfor; ?>
                            </select></label>
                        </span><?php endif; ?>
                    </div>
                </td></tr>
                <tr><th scope="row">Check</th><td>
                    <div class="attribute-content">
                        <span class="device-read-value"><?= (int) ($edit['check_excluded'] ?? 0) === 1 ? 'Ausgeschlossen' : 'Aktiv' ?></span>
                        <?php if (isAdmin()): ?><span class="device-editor">
                            <select name="check_excluded" aria-label="Check-Status" <?= !$editing ? 'disabled' : '' ?>>
                                <option value="0" <?= (string) ($edit['check_excluded'] ?? '0') === '0' ? 'selected' : '' ?>>Aktiv</option>
                                <option value="1" <?= (string) ($edit['check_excluded'] ?? '0') === '1' ? 'selected' : '' ?>>Ausgeschlossen</option>
                            </select>
                        </span><?php endif; ?>
                    </div>
                </td></tr>
                <tr><th scope="row">Notizen</th><td>
                    <div class="attribute-content attribute-notes" title="<?= h((string) ($edit['notes'] ?? '')) ?>">
                        <span class="device-read-value"><?= nl2br(h(($edit['notes'] ?? '') ?: '—')) ?></span>
                        <?php if (isAdmin()): ?><span class="device-editor">
                            <textarea name="notes" aria-label="Notizen" maxlength="4000" rows="2" <?= !$editing ? 'disabled' : '' ?>><?= h($edit['notes'] ?? '') ?></textarea>
                        </span><?php endif; ?>
                    </div>
                </td></tr>
                <?php if (!$isNew): ?>
                    <tr><th scope="row">Erstmals erfasst</th><td><span class="attribute-static-value"><?= h(rackIncidentDate($device['first_seen'])) ?></span></td></tr>
                    <tr><th scope="row">Letzter Check</th><td><span class="attribute-static-value"><?= h(rackIncidentDate($device['last_checked_at'])) ?></span></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (isAdmin()): ?>
            <div class="device-editor-actions" <?= !$editing ? 'hidden' : '' ?> data-device-actions>
                <a class="button outline" href="<?= $isNew ? 'details.php#inventory' : 'device.php?id=' . $id ?>" data-device-edit-cancel>Abbrechen</a>
                <button class="button light" type="submit"><?= $isNew ? 'Gerät anlegen' : 'Änderungen speichern' ?></button>
            </div>
        </form>
        <?php endif; ?>
    </section>

    <?php if (!$isNew): ?>
    <section class="panel" id="incidents" aria-labelledby="device-incidents-heading">
        <div class="section-top"><h2 id="device-incidents-heading">Incidents <span class="pill <?= $openIssues ? 'red-pill' : 'green-pill' ?>"><?= count($openIssues) ?> offen</span></h2></div>
        <div class="table-scroll">
            <table class="device-issue-table">
                <thead><tr><th>Status</th><th>Typ</th><th>Meldung</th><th>Erstmals</th><th>Zuletzt</th><th>Gelöst</th><th>Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($allIssues as $issue): ?>
                    <tr>
                        <td><span class="pill <?= $issue['resolved_at'] === null ? 'red-pill' : 'green-pill' ?>"><?= $issue['resolved_at'] === null ? 'Offen' : 'Gelöst' ?></span></td>
                        <td><?= h($issue['type']) ?></td>
                        <td class="issue-description"><?= h($issue['description']) ?></td>
                        <td class="date-cell"><?= h(rackIncidentDate($issue['detected_at'])) ?></td>
                        <td class="date-cell"><?= h(rackIncidentDate($issue['last_seen_at'] ?: $issue['detected_at'])) ?></td>
                        <td class="date-cell"><?= h(rackIncidentDate($issue['resolved_at'])) ?></td>
                        <td class="device-incident-actions">
                            <?php if (isAdmin()): ?>
                                <?php if ($issue['resolved_at'] === null): ?><form method="post" action="device.php?id=<?= $id ?>#incidents">
                                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="resolve_issue"><input type="hidden" name="issue_id" value="<?= (int) $issue['id'] ?>">
                                    <button type="submit" class="small-action secondary-action">Lösen</button>
                                </form><?php endif; ?>
                                <?php if ($issue['source_key'] === null && !str_starts_with((string) $issue['type'], 'Python-Check: ')): ?>
                                    <details class="incident-delete-confirm"><summary>Löschen</summary>
                                        <form method="post" action="device.php?id=<?= $id ?>#incidents">
                                            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="delete_issue"><input type="hidden" name="issue_id" value="<?= (int) $issue['id'] ?>">
                                            <button type="submit" class="small-action secondary-action">Bestätigen</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$allIssues): ?><tr><td colspan="7" class="empty-table">Keine Incidents vorhanden.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (isAdmin()): ?><details class="detail-add-incident" <?= ($_POST['action'] ?? '') === 'add_issue' && $error ? 'open' : '' ?>><summary>Incident hinzufügen</summary>
            <form class="device-form" method="post" action="device.php?id=<?= $id ?>#incidents">
                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="add_issue">
                <div class="form-row"><label>Typ<select name="type" required>
                    <?php foreach (['Warnung', 'Kritisch', 'Nicht erreichbar', 'Info'] as $type): ?><option value="<?= h($type) ?>" <?= ($_POST['type'] ?? '') === $type ? 'selected' : '' ?>><?= h($type) ?></option><?php endforeach; ?>
                </select></label><label>Meldung<textarea name="description" maxlength="2000" rows="2" required><?= h(($_POST['action'] ?? '') === 'add_issue' ? ($_POST['description'] ?? '') : '') ?></textarea></label></div>
                <div class="dialog-actions"><button class="button light" type="submit">Incident anlegen</button></div>
            </form>
        </details><?php endif; ?>
    </section>
    <?php if (isAdmin()): ?><details class="device-remove-panel"><summary>Gerät aus dem Inventar entfernen</summary>
        <form method="post" action="device.php?id=<?= $id ?>">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="remove_device">
            <p>Das Gerät wird ausgeblendet; Incidents bleiben erhalten.</p>
            <button type="submit" class="button danger">Gerät entfernen</button>
        </form>
    </details><?php endif; ?>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../layout/bottom.php'; ?>
