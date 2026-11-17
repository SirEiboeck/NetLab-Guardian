<section class="dashboard-panel" id="issues" aria-labelledby="incidents-heading">
    <div class="panel-heading">
        <div><h2 id="incidents-heading">Incidents</h2></div>
        <nav class="incident-filters" aria-label="Incidents filtern">
            <?php foreach (['open' => 'Offen', 'resolved' => 'Gelöst', 'all' => 'Alle'] as $key => $label): ?>
                <a href="details.php?incidents=<?= h($key) ?>#issues" class="incident-filter <?= $incidentFilter === $key ? 'active' : '' ?>" <?= $incidentFilter === $key ? 'aria-current="page"' : '' ?>><?= h($label) ?> <span><?= (int) $incidentCounts[$key] ?></span></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="table-scroll data-table-wrap">
        <table class="data-table incident-table">
            <thead><tr>
                <th scope="col">Status</th><th scope="col">Gerät</th><th scope="col">Check / Quelle</th>
                <th scope="col">Meldung</th><th scope="col">Erkannt</th><th scope="col">Zuletzt</th>
                <th scope="col">Gelöst am</th><th scope="col">Aktionen</th>
            </tr></thead>
            <tbody>
            <?php foreach ($incidents as $incident):
                $isOpen = $incident['resolved_at'] === null;
                $label = preg_replace('/^Python-Check:\s*/', '', (string) $incident['type']);
                $checkName = rackIncidentCommand($incident['source_key']);
                $detail = (string) $incident['description'];
                if ($checkName !== 'Manuell' && str_starts_with($detail, $checkName . ': ')) {
                    $detail = substr($detail, strlen($checkName) + 2);
                }
            ?>
                <tr>
                    <td><span class="incident-status <?= $isOpen ? 'open' : 'resolved' ?>"><span class="incident-status-dot" aria-hidden="true"></span><?= $isOpen ? 'Offen' : 'Gelöst' ?></span></td>
                    <td class="incident-device"><strong><?= h($incident['device_name'] ?: 'Gerät ' . $incident['device_id']) ?></strong><small><?= h($incident['device_ip'] ?: 'Keine IP') ?></small></td>
                    <td><span class="incident-command"><?= h($checkName) ?></span></td>
                    <td class="incident-message"><strong><?= h($label) ?></strong><?php if (trim($detail) !== ''): ?><span><?= h($detail) ?></span><?php endif; ?></td>
                    <td class="incident-date"><?= h(rackIncidentDate($incident['detected_at'])) ?></td>
                    <td class="incident-date"><?= h(rackIncidentDate($incident['last_seen_at'] ?: $incident['detected_at'])) ?></td>
                    <td class="incident-date"><?= h(rackIncidentDate($incident['resolved_at'])) ?></td>
                    <td class="incident-link">
                        <div class="incident-direct-actions">
                            <?php if (isAdmin() && $isOpen): ?>
                                <form method="post" action="details.php#issues">
                                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                    <input type="hidden" name="action" value="resolve_issue">
                                    <input type="hidden" name="issue_id" value="<?= (int) $incident['id'] ?>">
                                    <input type="hidden" name="incidents" value="<?= h($incidentFilter) ?>">
                                    <button type="submit" class="small-action incident-resolve-button" aria-label="Incident #<?= (int) $incident['id'] ?> lösen">Lösen</button>
                                </form>
                            <?php endif; ?>
                            <?php if (isAdmin() && $incident['source_key'] === null && !str_starts_with((string) $incident['type'], 'Python-Check: ')): ?>
                                <form method="post" action="details.php#issues" data-confirm="Diesen manuellen Incident wirklich dauerhaft löschen?">
                                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                    <input type="hidden" name="action" value="delete_issue">
                                    <input type="hidden" name="issue_id" value="<?= (int) $incident['id'] ?>">
                                    <input type="hidden" name="incidents" value="<?= h($incidentFilter) ?>">
                                    <button type="submit" class="small-action incident-delete-button" aria-label="Incident #<?= (int) $incident['id'] ?> löschen">Löschen</button>
                                </form>
                            <?php endif; ?>
                            <a href="device.php?id=<?= (int) $incident['device_id'] ?>#incidents" aria-label="<?= h($incident['device_name'] ?: 'Gerät') ?> öffnen">Details</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$incidents): ?><tr><td colspan="8" class="empty-table"><?= $incidentFilter === 'open' ? 'Keine offenen Incidents vorhanden.' : ($incidentFilter === 'resolved' ? 'Noch keine gelösten Incidents vorhanden.' : 'Noch keine Incidents vorhanden.') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
