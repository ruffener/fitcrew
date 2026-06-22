<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/inc/bootstrap.php';

$title = 'Google Health Status';
$contentView = 'views/app/health/google_status.php';
require fc_path('views/layouts/public.php');
