<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/product/bootstrap.php';

fc_require_login();
$currentUser = fc_current_user();
$pdo = fc_db();
$appContext = fc_product_context($pdo, (int) $currentUser['user_id']);
$appSection = 'account';
$title = 'Account';
$contentView = 'views/app/account.php';
require fc_path('views/layouts/app.php');
