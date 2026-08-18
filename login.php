<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$entryIntent = (string) ($_GET['intent'] ?? 'signin');
$entryIntent = $entryIntent === 'create' ? 'create' : 'signin';

$title = $entryIntent === 'create' ? 'Create Account' : 'Account Access';
$contentView = 'views/auth/login.php';
require fc_path('views/layouts/auth.php');
