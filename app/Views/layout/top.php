<?php
// Gemeinsames Layout für alle geschützten Seiten.
$pageTitle = (string) ($pageTitle ?? 'Übersicht');
$pageKey = (string) ($pageKey ?? 'dashboard');
$bodyClass = (string) ($bodyClass ?? '');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101820">
    <meta name="description" content="NetLab Guardian – Inventar, Netzwerkchecks und Incidents">
    <title><?= h($pageTitle) ?> · NetLab Guardian</title>
    <link rel="stylesheet" href="style.css?v=20261117-incident-direct-1">
    <script src="assets/js/interface.js?v=20261117-incident-direct-1" defer></script>
</head>
<body class="<?= h($bodyClass) ?>">
<div class="app-shell">
    <aside class="app-sidebar" aria-label="Seitennavigation">
        <a class="app-logo" href="index.php"><span class="logo-symbol" aria-hidden="true">N</span><span>NetLab <b>Guardian</b></span></a>
        <div class="sidebar-group-label">Monitoring</div>
        <nav class="sidebar-nav" aria-label="Monitoring">
            <a class="<?= $pageKey === 'dashboard' ? 'is-active' : '' ?>" href="index.php"><span aria-hidden="true">▦</span> Übersicht</a>
            <a class="<?= $pageKey === 'details' || $pageKey === 'device' ? 'is-active' : '' ?>" href="details.php"><span aria-hidden="true">▤</span> Geräte &amp; Incidents</a>
        </nav>
        <?php if (isAdmin()): ?>
        <div class="sidebar-group-label">Verwaltung</div>
        <nav class="sidebar-nav" aria-label="Verwaltung">
            <a class="<?= $pageKey === 'credentials' ? 'is-active' : '' ?>" href="credentials.php"><span aria-hidden="true">◈</span> Zugangsdaten</a>
            <a class="<?= $pageKey === 'users' ? 'is-active' : '' ?>" href="site_users.php"><span aria-hidden="true">♙</span> Benutzer</a>
        </nav>
        <?php endif; ?>
        <div class="sidebar-bottom">
            <div class="signed-in"><span class="account-avatar" aria-hidden="true"><?= h(strtoupper(substr((string) $_SESSION['user'], 0, 1))) ?></span><span><b><?= h($_SESSION['user']) ?></b><small><?= isAdmin() ? 'Administrator' : 'Viewer' ?></small></span></div>
            <form method="post" action="logout.php" class="sidebar-logout">
                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                <button type="submit">Abmelden <span aria-hidden="true">↗</span></button>
            </form>
        </div>
    </aside>
    <div class="app-content">
        <header class="app-topbar">
            <div><span class="topbar-title"><?= h($pageTitle) ?></span></div>
            
        </header>
