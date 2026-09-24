<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
// Admin failures never disclose database/configuration details, even in debug mode.
ini_set('display_errors', '0');
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/presentation.php';
require_once __DIR__ . '/controller.php';
