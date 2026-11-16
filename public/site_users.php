<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../app/Controllers/site_users.php';
$pageKey = 'users';
$pageTitle = 'Benutzerverwaltung';
$bodyClass = 'admin-page';
require __DIR__ . '/../app/Views/pages/site_users.php';
