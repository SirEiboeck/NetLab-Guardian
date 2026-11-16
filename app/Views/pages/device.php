<?php require __DIR__ . '/../layout/top.php'; ?>
<main class="workspace detail-workspace">
        <div class="eyebrow muted">GERÄTEDETAILS</div>
        <div class="section-top">
            <h1><?= h($device['hostname'] ?: 'Gerät ' . $id) ?></h1><span
                class="pill <?= pillClass($status) ?>"><?= h(statusLabel($status)) ?></span>
        </div>
        <?php if ($flash): ?>
            <p class="notice success" role="status"><?= h($flash) ?></p><?php endif; ?>
        <?php if ($error): ?>
            <p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>
        <div class="panel">
            <h2>Geräteinformationen</h2>
            <div class="dialog-grid">
                <?php foreach ([
                    'Gerätetyp' => $device['device_type'],
                    'Modell' => $device['model'],
                    'Management-IP' => $device['management_ip'],
                    'Rackposition' => $device['rack_position'],
                    'Seriennummer' => $device['serial_number'],
                    'Firmware' => $device['firmware'],
                    'Konsolenport' => $device['console_port'],
                    'Inventarnummer' => $device['inventory_number'],
                    'Erstmals gesehen' => $device['first_seen'],
                    'Zuletzt geprüft' => $device['last_checked_at'],
                    'Prüfung und IP-Scan' => (int) ($device['check_excluded'] ?? 0) === 1 ? 'Ausgeschlossen' : 'Aktiv',
                ] as $label => $value): ?>
                    <div><span><?= h($label) ?></span><b><?= h($value ?: '—') ?></b></div><?php endforeach; ?>
            </div>
        </div>
        <div class="panel">
            <h2>Notizen</h2><?php if (trim((string) $device['notes']) !== ''): ?>
                <div class="saved-note">
                    <p><?= h($device['notes']) ?></p>
                </div><?php else: ?>
                <p class="subtle">Keine Notizen hinterlegt.</p><?php endif; ?>
            <?php if (isAdmin()): ?>
                <form class="device-form" method="post" action="device.php?id=<?= $id ?>"><input type="hidden" name="csrf"
                        value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="notes"><textarea name="notes"
                        maxlength="4000" rows="5" aria-label="Notizen"><?= h($device['notes']) ?></textarea>
                    <div class="dialog-actions"><button class="button light" type="submit">Notizen speichern</button></div>
                </form><?php endif; ?>
        </div>
        <section class="panel" aria-labelledby="device-incidents-heading">
            <h2 id="device-incidents-heading">Störungen <span class="pill red-pill"><?= count($openIssues) ?> offen</span></h2>
            <div class="table-scroll">
                <table class="device-issue-table">
                    <thead><tr><th scope="col">Status</th><th scope="col">Art</th><th scope="col">Beschreibung</th><th scope="col">Erkannt</th><th scope="col">Gelöst</th><th scope="col"><span class="visually-hidden">Aktion</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($allIssues as $issue): ?>
                        <tr>
                            <td><span class="pill <?= $issue['resolved_at'] === null ? 'red-pill' : 'green-pill' ?>"><?= $issue['resolved_at'] === null ? 'Offen' : 'Gelöst' ?></span></td>
                            <td><?= h($issue['type']) ?></td>
                            <td class="issue-description"><?= h($issue['description']) ?></td>
                            <td><?= h($issue['detected_at']) ?></td>
                            <td><?= h($issue['resolved_at'] ?: '—') ?></td>
                            <td>
                                <?php if (isAdmin() && $issue['resolved_at'] === null): ?>
                                <form method="post" action="device.php?id=<?= $id ?>">
                                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                    <input type="hidden" name="action" value="resolve_issue">
                                    <input type="hidden" name="issue_id" value="<?= (int) $issue['id'] ?>">
                                    <button type="submit" class="button outline">Als gelöst markieren</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$allIssues): ?><tr><td colspan="6">Zu diesem Gerät sind keine Störungen erfasst.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (isAdmin()): ?>
                <h3>Neue Störung erfassen</h3>
                <form class="device-form" method="post" action="device.php?id=<?= $id ?>"><input type="hidden" name="csrf"
                        value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="add_issue"><label>Typ *<select
                            name="type" required>
                            <option value="Warnung">Warnung</option>
                            <option value="Kritisch">Kritisch</option>
                            <option value="Nicht erreichbar">Nicht erreichbar</option>
                            <option value="Info">Info</option>
                        </select></label><label>Beschreibung *<textarea name="description" maxlength="2000" rows="4"
                            required><?= h($_POST['description'] ?? '') ?></textarea></label>
                    <div class="dialog-actions"><button type="submit" class="button light">Störung hinzufügen</button></div>
                </form><?php endif; ?>
        </section>
        <p class="subtle">Status wird aus offenen Störungen und dem letzten Prüfzeitpunkt abgeleitet; kein automatischer
            Netzwerkcheck.</p>
    </main>
<?php require __DIR__ . '/../layout/bottom.php'; ?>
