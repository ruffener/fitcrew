<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once dirname(__DIR__, 2) . '/inc/product/bootstrap.php';

fc_require_login();
$currentUser = fc_current_user();
$pdo = fc_db();
$appContext = fc_product_context($pdo, (int) $currentUser['user_id']);
$appSection = 'health';
$title = 'Health Connections';
$contentView = 'views/app/health/google_status.php';
require fc_path('views/layouts/app.php');
