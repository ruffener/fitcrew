<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$title = 'Reset Password';
$contentView = 'views/auth/reset_password.php';
require fc_path('views/layouts/auth.php');
