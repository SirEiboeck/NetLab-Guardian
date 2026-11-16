<?php require __DIR__ . '/layout/top.php'; ?>
<?php
$total = count($devices);
$healthy = $statusCount('checked');
$warning = $statusCount('warning');
$critical = $statusCount('critical');
$unknown = $statusCount('unknown');
?>
<main class="workspace dashboard-workspace" id="overview">
    <div class="dashboard-heading">
        <h1>Übersicht</h1>
        <div class="dashboard-heading-actions">
            <a class="button outline" href="details.php">Geräte &amp; Incidents</a>
            <?php if (isAdmin()): ?><a class="button light" href="device.php">+ Gerät hinzufügen</a><?php endif; ?>
        </div>
    </div>
    <section class="dashboard-panel status-overview" aria-labelledby="status-overview-heading">
        <div class="panel-heading"><h2 id="status-overview-heading">Statusübersicht</h2></div>
        <div class="donut-grid">
            <?php
            $chartId = 'device-chart';
            $chartTitle = 'Geräte';
            $chartTotal = $total;
            $chartCenter = $total;
            $chartCenterLabel = 'Geräte gesamt';
            $chartSegments = [
                ['label' => 'Geprüft', 'count' => $healthy, 'tone' => 'healthy'],
                ['label' => 'Warnung', 'count' => $warning, 'tone' => 'warning'],
                ['label' => 'Kritisch', 'count' => $critical, 'tone' => 'critical'],
                ['label' => 'Nicht geprüft', 'count' => $unknown, 'tone' => 'unknown'],
            ];
            require __DIR__ . '/dashboard/status_chart.php';

            $chartId = 'incident-chart';
            $chartTitle = 'Incidents';
            $chartTotal = (int) $incidentCounts['all'];
            $chartCenter = (int) $incidentCounts['open'];
            $chartCenterLabel = 'Offen';
            $chartSegments = [
                ['label' => 'Offen', 'count' => (int) $incidentCounts['open'], 'tone' => 'open'],
                ['label' => 'Gelöst', 'count' => (int) $incidentCounts['resolved'], 'tone' => 'resolved'],
            ];
            require __DIR__ . '/dashboard/status_chart.php';
            ?>
        </div>
    </section>
    <?php require __DIR__ . '/dashboard/racks.php'; ?>
</main>
<?php require __DIR__ . '/layout/bottom.php'; ?>
