<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
requireLogin();
require __DIR__ . '/../app/Checks/bootstrap.php';
require __DIR__ . '/../app/Views/rack_controls.php';
require __DIR__ . '/../app/Controllers/dashboard.php';

$pageTitle = 'Übersicht';
$pageKey = 'dashboard';
$bodyClass = 'dashboard-page';
require __DIR__ . '/../app/Views/dashboard.php';
