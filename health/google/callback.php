<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

fc_response_code(501);
$title = 'Google Health Callback';
$pageHeading = 'Google Health Callback Not Implemented';
$pageMessage = 'OAuth callback handling is intentionally excluded from Phase 1 skeleton.';
$contentView = 'views/app/placeholder.php';
require fc_path('views/layouts/public.php');
