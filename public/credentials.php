<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../app/Controllers/credentials.php';
$pageKey = 'credentials';
$pageTitle = 'Zugangsdaten';
$bodyClass = 'admin-page credentials-page';
require __DIR__ . '/../app/Views/pages/credentials.php';
