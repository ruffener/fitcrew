<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$title = 'Login';
$contentView = 'views/auth/login.php';
require fc_path('views/layouts/auth.php');
