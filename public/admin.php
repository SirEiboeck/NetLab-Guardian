<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../app/Controllers/admin.php';
$pageKey = 'admin';
$pageTitle = 'Geräteverwaltung';
$bodyClass = 'admin-page';
require __DIR__ . '/../app/Views/pages/admin.php';
