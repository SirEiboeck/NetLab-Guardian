<section class="dashboard-panel" id="racks" aria-labelledby="racks-heading">
    <div class="panel-heading">
        <h2 id="racks-heading">Racks</h2>
        <nav class="incident-filters" aria-label="Racks filtern">
            <a class="incident-filter <?= $filter === 'all' ? 'active' : '' ?>" href="index.php?filter=all#racks">Alle <span>3</span></a>
            <a class="incident-filter <?= $filter === 'issues' ? 'active' : '' ?>" href="index.php?filter=issues#racks">Mit Problemen <span><?= count($problemRacks) ?></span></a>
        </nav>
    </div>
    <?php
    $displayRacks = $filter === 'issues' ? array_keys($problemRacks) : [3, 2, 1];
    rsort($displayRacks);
    // Die sichtbare Höhe ist bei allen Racks gleich. Mindestens 10 HE,
    // bei belegter höherer Position wächst die gesamte Rack-Gruppe bis maximal 30 HE.
    $rackDisplayUnits = 10;
    foreach ($devices as $device) {
        if (in_array($device['rack'], $displayRacks, true) && $device['unit'] !== null) {
            $rackDisplayUnits = max($rackDisplayUnits, min(30, (int) $device['unit']));
        }
    }
    ?>
    <?php if ($displayRacks): ?>
        <div class="rack-gallery" aria-label="Rack-Belegung">
            <?php foreach ($displayRacks as $rack): ?>
                <?php require __DIR__ . '/rack_visual.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="empty-table rack-empty">Keine Racks mit offenen Problemen.</p>
    <?php endif; ?>
</section>
