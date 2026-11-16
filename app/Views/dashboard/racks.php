<section class="dashboard-panel" id="racks" aria-labelledby="racks-heading">
    <div class="panel-heading">
        <div><span class="section-overline">03 / HARDWARE</span><h2 id="racks-heading">Racks &amp; Checks</h2><p>Rack-Status und Prüfaktionen. Die grafische Belegung lässt sich darunter aufklappen.</p></div>
        <nav class="incident-filters" aria-label="Rack-Filter">
            <a class="incident-filter <?= $filter === 'all' ? 'active' : '' ?>" href="index.php?filter=all#racks">Alle Racks <span>3</span></a>
            <a class="incident-filter <?= $filter === 'issues' ? 'active' : '' ?>" href="index.php?filter=issues#racks">Mit Problemen <span><?= count($problemRacks) ?></span></a>
        </nav>
    </div>
    <?php $displayRacks = $filter === 'issues' ? array_keys($problemRacks) : [3, 2, 1]; rsort($displayRacks); ?>
    <div class="table-scroll data-table-wrap">
        <table class="data-table rack-overview-table">
            <thead><tr><th scope="col">Rack</th><th scope="col">Netz</th><th scope="col">Geräte</th><th scope="col">Status</th><th scope="col">Letzte Prüfung</th><th scope="col">Prüfung starten</th></tr></thead>
            <tbody>
                <?php foreach ($displayRacks as $rack):
                    $rackDevices = array_values(array_filter($devices, static fn(array $d): bool => $d['rack'] === $rack));
                    $rackStatuses = array_column($rackDevices, 'status');
                    $rackStatus = in_array('critical', $rackStatuses, true) ? 'critical' : (in_array('warning', $rackStatuses, true) ? 'warning' : (in_array('unknown', $rackStatuses, true) ? 'unknown' : 'checked'));
                    if (!$rackDevices) $rackStatus = 'unknown';
                    $lastChecks = array_filter(array_column($rackDevices, 'last_checked_at'));
                    $lastCheck = $lastChecks ? max($lastChecks) : null;
                ?>
                <tr>
                    <td><a class="table-primary-link" href="#rack-<?= $rack ?>">Rack <?= $rack ?></a></td>
                    <td class="mono-cell"><?= h(rackLabel($rack)) ?></td>
                    <td><?= count($rackDevices) ?> <span class="table-muted">Geräte</span></td>
                    <td><span class="pill <?= pillClass($rackStatus) ?>"><?= h(statusLabel($rackStatus)) ?></span></td>
                    <td class="date-cell"><?= h(rackIncidentDate($lastCheck)) ?></td>
                    <td>
                        <?php if (isAdmin()): ?>
                        <form method="post" action="index.php#rack-<?= $rack ?>" class="rack-inline-actions">
                            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                            <input type="hidden" name="rack" value="<?= $rack ?>">
                            <input type="hidden" name="mode" value="default">
                            <button type="submit" name="action" value="check" class="small-action">Geräte prüfen</button>
                            <button type="submit" name="action" value="scan" class="small-action secondary-action">IP-Scan + Prüfen</button>
                        </form>
                        <?php else: ?><span class="table-muted">Nur Admins</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$displayRacks): ?><tr><td colspan="6" class="empty-table">Keine Racks mit offenen Problemen.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="rack-disclosures">
        <?php foreach ($displayRacks as $rack): ?>
            <details class="rack-visual-details" <?= is_array($checkNotice) && (int) $checkNotice['rack'] === $rack ? 'open' : '' ?>>
                <summary><span>Rack <?= $rack ?> – Belegung &amp; erweiterte Prüfoptionen</span><span class="toggle-label">Anzeigen</span></summary>
                <div class="rack-visual-content"><div class="racks">
                    <?php require __DIR__ . '/rack_visual.php'; ?>
                </div></div>
            </details>
        <?php endforeach; ?>
    </div>
</section>
