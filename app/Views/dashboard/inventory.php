<section class="dashboard-panel" id="inventory" aria-labelledby="inventory-heading">
    <div class="panel-heading">
        <div><h2 id="inventory-heading">Geräte</h2></div>
        <form class="inventory-search" method="get" action="details.php#inventory">
            <label class="visually-hidden" for="device-query">Gerät oder IP suchen</label>
            <input id="device-query" name="q" type="search" value="<?= h($query) ?>" placeholder="Hostname, IP, Modell suchen …">
            <button type="submit" class="button outline">Suchen</button>
            <?php if ($query !== ''): ?><a class="text-button" href="details.php#inventory">Zurücksetzen</a><?php endif; ?>
        </form>
    </div>
    <div class="table-scroll data-table-wrap">
        <table class="data-table inventory-table">
            <thead><tr><th scope="col">Hostname</th><th scope="col">Typ / Modell</th><th scope="col">Management-IP</th><th scope="col">Rack / HE</th><th scope="col">Status</th><th scope="col">Letzter Check</th><th scope="col"><span class="visually-hidden">Aktion</span></th></tr></thead>
            <tbody>
                <?php foreach ($visible as $d): ?>
                <tr>
                    <td><a class="table-primary-link" href="device.php?id=<?= (int) $d['id'] ?>"><?= h($d['hostname'] ?: 'Gerät ' . $d['id']) ?></a><?= trim((string) $d['notes']) !== '' ? ' <span class="has-note-tag" title="Notiz vorhanden">Notiz</span>' : '' ?></td>
                    <td><?= h($d['model'] ?: $d['device_type'] ?: '—') ?></td>
                    <td class="mono-cell"><?= h($d['management_ip'] ?: '—') ?></td>
                    <td><?= h($d['rack_position'] ?: 'Nicht zugeordnet') ?></td>
                    <td><span class="pill <?= pillClass($d['status']) ?>"><?= h(statusLabel($d['status'])) ?></span><?php if ((int) ($d['check_excluded'] ?? 0) === 1): ?> <span class="pill neutral-pill">Ausgeschlossen</span><?php endif; ?></td>
                    <td class="date-cell"><?= h(rackIncidentDate($d['last_checked_at'])) ?></td>
                    <td><a class="table-action" href="device.php?id=<?= (int) $d['id'] ?>">Ansehen</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$visible): ?><tr><td colspan="7" class="empty-table">Keine Geräte gefunden.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="panel-footnote"><?= count($visible) ?> von <?= count($devices) ?> Geräten angezeigt</p>
</section>
