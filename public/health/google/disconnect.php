<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/inc/bootstrap.php';

fc_response_code(501);
$title = 'Google Health Disconnect';
$pageHeading = 'Google Health Disconnect Not Implemented';
$pageMessage = 'Health-provider disconnect behavior is intentionally excluded from Phase 1 skeleton.';
$contentView = 'views/app/placeholder.php';
require fc_path('views/layouts/public.php');
