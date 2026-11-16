<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../app/Controllers/login.php';
$pageKey = 'login';
$pageTitle = 'Anmelden';
$bodyClass = 'login-page';
require __DIR__ . '/../app/Views/pages/login.php';
