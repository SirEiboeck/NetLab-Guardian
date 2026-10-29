<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
requireLogin();
$devices = $db->activeDevices();
$issues = $db->openIssues();
foreach ($devices as &$device) {
    [$device['rack'], $device['unit']] = rackAndUnit($device['rack_position']);
    $device['status'] = statusFor((int) $device['id'], $issues, $device['last_checked_at']);
}
unset($device);
$deviceById = array_column($devices, null, 'id');
$statuses = array_count_values(array_column($devices, 'status'));
$statusCount = static fn(string $status): int => $statuses[$status] ?? 0;
$problemRacks = [];
foreach ($devices as $d) {
    if ($d['rack'] !== null && in_array($d['status'], ['warning', 'critical'], true))
        $problemRacks[$d['rack']] = true;
}
$filter = ($_GET['filter'] ?? '') === 'issues' ? 'issues' : 'all';
$query = trim((string) ($_GET['q'] ?? ''));
$visible = array_values(array_filter($devices, static function (array $d) use ($query): bool {
    if ($query === '')
        return true;
    return str_contains(strtolower(implode(' ', [
        $d['hostname'],
        $d['management_ip'],
        $d['device_type'],
        $d['model'],
        $d['serial_number'],
        $d['rack_position'],
        statusLabel($d['status']),
    ])), strtolower($query));
}));
usort($visible, static fn(array $a, array $b): int => (($b['rack'] ?? 0) <=> ($a['rack'] ?? 0)) ?: (($b['unit'] ?? 0) <=> ($a['unit'] ?? 0)));
?>
<!doctype html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#09090b">
    <meta name="description" content="NetLab Guardian Netzwerkgeräteinventar">
    <title>NetLab Guardian — Netzwerk im Blick</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <header class="header"><a class="brand" href="index.php">NetLab Guardian</a>
        <nav aria-label="Hauptnavigation"><a class="active" href="#overview">Übersicht</a><a
                href="#inventory">Geräteinventar</a><a href="#issues">Probleme <span
                    class="nav-count"><?= count($issues) ?></span></a></nav>
        <div class="header-actions"><span class="user-badge"><?= h($_SESSION['user']) ?> ·
                <?= h($_SESSION['role']) ?></span><?php if (isAdmin()): ?><a class="button outline"
                    href="admin.php">Adminbereich</a><?php endif; ?>
            <form class="logout-form" method="post" action="logout.php"><input type="hidden" name="csrf"
                    value="<?= h(csrf()) ?>"><button class="button outline" type="submit">Abmelden</button></form>
        </div>
    </header>
    <main>
        <section class="hero" aria-labelledby="heroTitle">
            <div class="hero-art" aria-hidden="true"></div>
            <div class="hero-overlay" aria-hidden="true"></div>
            <div class="hero-copy">
                <div class="eyebrow">Netzwerkverwaltung</div>
                <h1 id="heroTitle">Das Labornetzwerk<br>im Überblick</h1>
                <p>Inventar und bekannte Probleme der Switches und Router in drei Labor-Racks.</p><a class="hero-link"
                    href="#overview">Zum Rack-Monitor <span>↓</span></a>
            </div>
            <div class="hero-bottom"><span>SQLite-Inventar</span><span>3 Racks <b>/</b> <?= count($devices) ?> Geräte
                    <b>/</b> Labor-Netzwerk</span></div>
        </section>
        <section class="workspace" id="overview">
            <div class="section-top">
                <div>
                    <div class="eyebrow muted">LABORÜBERSICHT</div>
                    <h2>Rack-Übersicht</h2>
                </div>
                <div class="sync">Inventardaten aus SQLite · kein Live-Ping</div>
            </div>
            <div class="stats">
                <div><span>Geräte insgesamt</span><strong><?= count($devices) ?> <small><?= $statusCount('unknown') ?>
                            nicht geprüft</small></strong></div>
                <div><span>Zuletzt geprüft</span><strong><?= $statusCount('checked') ?> <small>Ohne offene
                            Probleme</small></strong></div>
                <div><span>Warnungen</span><strong><?= $statusCount('warning') ?> <small>Offene
                            Meldungen</small></strong></div>
                <div><span>Kritisch</span><strong class="red-text"><?= $statusCount('critical') ?> <small>Aktion
                            erforderlich</small></strong></div>
            </div>
            <div class="rack-toolbar">
                <div class="segmented" aria-label="Rack-Filter"><a class="<?= $filter === 'all' ? 'selected' : '' ?>"
                        href="index.php?filter=all#overview">Alle Racks <span>3</span></a><a
                        class="<?= $filter === 'issues' ? 'selected' : '' ?>"
                        href="index.php?filter=issues#overview">Mit Problemen
                        <span><?= count($problemRacks) ?></span></a></div><span class="rack-hint">Gerät auswählen für
                    Details</span>
            </div>
            <div class="racks">
                <?php $displayRacks = $filter === 'issues' ? array_keys($problemRacks) : [3, 2, 1];
                rsort($displayRacks); ?>
                <?php foreach ($displayRacks as $rack): ?>
                    <?php
                    $rackDevices = array_values(array_filter($devices, static fn(array $d): bool => $d['rack'] === $rack));
                    $rackStatuses = array_column($rackDevices, 'status');
                    $rackStatus = in_array('critical', $rackStatuses, true) ? 'critical' : (in_array('warning', $rackStatuses, true) ? 'warning' : (in_array('unknown', $rackStatuses, true) ? 'unknown' : 'checked'));
                    $rackSlots = [];
                    foreach ($rackDevices as $d)
                        $rackSlots[$d['unit']] = $d;
                    $switches = count(array_filter($rackDevices, static fn($d) => strtolower($d['device_type']) === 'switch'));
                    $routers = count(array_filter($rackDevices, static fn($d) => strtolower($d['device_type']) === 'router'));
                    ?>
                    <article class="rack-card">
                        <div class="rack-heading">
                            <h3>Rack <?= $rack ?> — <?= h(rackLabel($rack)) ?></h3><span
                                class="pill <?= pillClass($rackStatus) ?>"><?= h(statusLabel($rackStatus)) ?></span>
                        </div>
                        <div class="rack-frame">
                            <?php for ($unit = 1; $unit <= 30; ++$unit): ?>
                                <?php if (!isset($rackSlots[$unit])): ?>
                                    <div class="empty-unit"><span
                                            class="unit"><?= str_pad((string) $unit, 2, '0', STR_PAD_LEFT) ?></span><span>Frei</span>
                                    </div>
                                <?php else:
                                    $d = $rackSlots[$unit];
                                    $isRouter = strtolower($d['device_type']) === 'router'; ?>
                                    <a class="device <?= h($d['status']) ?> <?= $isRouter ? 'router' : '' ?> <?= trim((string) $d['notes']) !== '' ? 'has-note' : '' ?>"
                                        href="device.php?id=<?= (int) $d['id'] ?>"
                                        aria-label="<?= h($d['hostname'] ?: 'Gerät ' . $d['id']) ?>, <?= h(statusLabel($d['status'])) ?>, Details öffnen"><span
                                            class="unit"><?= str_pad((string) $unit, 2, '0', STR_PAD_LEFT) ?></span><span
                                            class="device-name"><?= h($d['hostname'] ?: 'Gerät ' . $d['id']) ?></span><?php if (trim((string) $d['notes']) !== ''): ?><span
                                                class="note-marker" title="Notiz vorhanden">N</span><?php endif; ?><span class="ports"
                                            aria-hidden="true"><?php for ($p = 0; $p < ($isRouter ? 4 : 16); ++$p): ?><i
                                                    class="port"></i><?php endfor; ?></span></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                        <div class="rack-footer"><span><b><?= count($rackDevices) ?> Geräte</b> / <?= $switches ?> Switches
                                / <?= $routers ?> Router</span><span><?= count($rackSlots) ?> / 30 HE</span></div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$displayRacks): ?>
                    <p class="subtle">Keine Racks mit offenen Problemen.</p><?php endif; ?>
            </div>
            <div class="legend"><span>Geprüft</span><span>Warnung</span><span>Kritisch</span><span>Nicht
                    geprüft</span><span>19″ Racks · 30 HE · schematische Darstellung</span></div>
            <section class="issues" id="issues">
                <div class="section-top">
                    <div>
                        <div class="eyebrow muted">AKTUELLE MELDUNGEN</div>
                        <h2>Offene Probleme <span class="pill red-pill"><?= count($issues) ?></span></h2>
                    </div><span class="subtle">Aus der SQLite-Tabelle issue</span>
                </div>
                <?php foreach ($issues as $issue):
                    $d = $deviceById[$issue['device_id']] ?? null; ?>
                    <div class="issue-row"><span class="issue-icon <?= issueCritical($issue) ? '' : 'warn' ?>">!</span>
                        <div class="issue-copy"><strong><?= h($issue['type']) ?></strong>
                            <p><?= h($d['hostname'] ?? 'Gerät ' . $issue['device_id']) ?> — <?= h($issue['description']) ?>
                            </p>
                        </div><?php if ($d): ?><a class="text-button" href="device.php?id=<?= (int) $d['id'] ?>">Prüfen
                                ↗</a><?php else: ?><span class="subtle">Gerät nicht aktiv</span><?php endif; ?>
                    </div><?php endforeach; ?>
                <?php if (!$issues): ?>
                    <p class="subtle">Keine offenen Probleme erfasst.</p><?php endif; ?>
            </section>
            <section class="inventory" id="inventory">
                <div class="section-top">
                    <div>
                        <div class="eyebrow muted">INVENTAR / SQLite</div>
                        <h2>Geräteinventar</h2>
                    </div>
                    <form class="search" method="get" action="index.php#inventory"><span>⌕</span><input name="q"
                            type="search" value="<?= h($query) ?>" placeholder="Gerät oder IP suchen …"
                            aria-label="Gerät oder IP suchen"><button type="submit" class="text-button">Suchen</button>
                    </form>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Gerät</th>
                                <th>Typ / Modell</th>
                                <th>IP-Adresse</th>
                                <th>Standort</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visible as $d): ?>
                                <tr>
                                    <td><?= h($d['hostname'] ?: 'Gerät ' . $d['id']) ?><?= trim((string) $d['notes']) !== '' ? ' Ⓝ' : '' ?>
                                    </td>
                                    <td><?= h($d['model'] ?: $d['device_type']) ?></td>
                                    <td><?= h($d['management_ip'] ?: '—') ?></td>
                                    <td><?= h($d['rack_position'] ?: 'Ohne Rack') ?></td>
                                    <td><span
                                            class="pill <?= pillClass($d['status']) ?>"><?= h(statusLabel($d['status'])) ?></span>
                                    </td>
                                    <td><a class="text-button" href="device.php?id=<?= (int) $d['id'] ?>">Details ↗</a></td>
                                </tr><?php endforeach; ?>
                            <?php if (!$visible): ?>
                                <tr>
                                    <td colspan="6">Keine Geräte gefunden.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="subtle"><?= count($visible) ?> von <?= count($devices) ?> Geräten</p>
            </section>
        </section>
    </main>
    <footer><a class="brand" href="index.php">NetLab Guardian</a><span>Netzwerkverwaltung für den
            Unterricht</span><span>Inventardaten · keine automatische Geräteprüfung</span></footer>
</body>

</html>