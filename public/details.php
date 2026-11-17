<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
requireLogin();
require __DIR__ . '/../app/Checks/bootstrap.php';
require __DIR__ . '/../app/Controllers/incident_actions.php';
require __DIR__ . '/../app/Controllers/monitoring.php';
$pageTitle = 'Geräte & Incidents';
$pageKey = 'details';
$bodyClass = 'dashboard-page';
$flash = takeFlash();
require __DIR__ . '/../app/Views/details.php';
