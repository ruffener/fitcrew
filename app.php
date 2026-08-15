<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

fc_require_login();

$title = 'App';
$contentView = 'views/app/dashboard.php';
require fc_path('views/layouts/app.php');
