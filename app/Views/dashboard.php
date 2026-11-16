<?php require __DIR__ . '/layout/top.php'; ?>
<main class="workspace dashboard-workspace" id="overview">
    <div class="dashboard-heading">
        <div>
            <p class="dashboard-kicker">MONITORING / NETZWERKLABOR</p>
            <h1>Netzwerkübersicht</h1>
            <p class="subtle">Geräte, aktuelle Störungen und Rack-Prüfungen auf einen Blick.</p>
        </div>
        <?php if (isAdmin()): ?><a href="admin.php?edit=new#editor" class="button light">+ Gerät hinzufügen</a><?php endif; ?>
    </div>
    <section class="metric-grid" aria-label="Statuszahlen">
        <div class="metric"><span>Geräte insgesamt</span><strong><?= count($devices) ?></strong><small>Im aktiven Inventar</small></div>
        <div class="metric"><span>Ohne offene Probleme</span><strong><?= $statusCount('checked') ?></strong><small>Zuletzt erfolgreich geprüft</small></div>
        <div class="metric"><span>Warnungen</span><strong><?= $statusCount('warning') ?></strong><small>Betroffene Geräte</small></div>
        <div class="metric metric-danger"><span>Kritische Geräte</span><strong><?= $statusCount('critical') ?></strong><small>Handlungsbedarf</small></div>
        <div class="metric"><span>Offene Incidents</span><strong><?= (int) $incidentCounts['open'] ?></strong><small><?= $statusCount('unknown') ?> nicht geprüfte Geräte</small></div>
    </section>
    <?php require __DIR__ . '/dashboard/incidents.php'; ?>
    <?php require __DIR__ . '/dashboard/inventory.php'; ?>
    <?php require __DIR__ . '/dashboard/racks.php'; ?>
</main>
<?php require __DIR__ . '/layout/bottom.php'; ?>
