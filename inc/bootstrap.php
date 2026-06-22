<?php

declare(strict_types=1);

require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/config/env.php';

fc_load_env(fc_path('.env'));

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/auth/sessions.php';
require_once __DIR__ . '/support/flash.php';
require_once __DIR__ . '/support/logger.php';
require_once __DIR__ . '/support/dates.php';
require_once __DIR__ . '/security/escape.php';
require_once __DIR__ . '/security/validation.php';
require_once __DIR__ . '/security/rate_limit.php';
require_once __DIR__ . '/http/request.php';
require_once __DIR__ . '/http/response.php';
require_once __DIR__ . '/http/redirect.php';
require_once __DIR__ . '/http/csrf.php';
require_once __DIR__ . '/auth/passwords.php';
require_once __DIR__ . '/auth/permissions.php';
require_once __DIR__ . '/auth/auth.php';
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/db/migrations.php';

$config = fc_config();
date_default_timezone_set($config['timezone']);

if ($config['debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
}

fc_start_session();
