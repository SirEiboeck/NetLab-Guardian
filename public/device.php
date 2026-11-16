<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../app/Controllers/device.php';
$pageKey = 'device';
$pageTitle = (string) ($device['hostname'] ?: 'Gerät ' . $id);
$bodyClass = 'detail-page';
require __DIR__ . '/../app/Views/pages/device.php';
