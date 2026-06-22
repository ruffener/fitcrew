<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$title = 'Forgot Password';
$contentView = 'views/auth/forgot_password.php';
require fc_path('views/layouts/auth.php');
