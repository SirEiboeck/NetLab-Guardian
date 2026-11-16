<?php require __DIR__ . '/layout/top.php'; ?>
<main class="workspace dashboard-workspace detail-tables-workspace">
    <div class="dashboard-heading">
        <h1>Geräte &amp; Incidents</h1>
        <?php if (isAdmin()): ?><a href="device.php" class="button light">+ Gerät hinzufügen</a><?php endif; ?>
    </div>
    <?php if ($flash): ?><p class="notice success" role="status"><?= h($flash) ?></p><?php endif; ?>
    <?php require __DIR__ . '/dashboard/inventory.php'; ?>
    <?php require __DIR__ . '/dashboard/incidents.php'; ?>
</main>
<?php require __DIR__ . '/layout/bottom.php'; ?>
