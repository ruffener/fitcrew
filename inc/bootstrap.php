<?php

declare(strict_types=1);

require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/config/env.php';

fc_load_env(fc_path('.env'));

$composerAutoload = fc_path('vendor/autoload.php');
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/auth/sessions.php';
require_once __DIR__ . '/support/flash.php';
require_once __DIR__ . '/support/dates.php';
require_once __DIR__ . '/security/escape.php';
require_once __DIR__ . '/security/redaction.php';
require_once __DIR__ . '/security/protected_secrets.php';
require_once __DIR__ . '/support/logger.php';
require_once __DIR__ . '/security/validation.php';
require_once __DIR__ . '/security/rate_limit.php';
require_once __DIR__ . '/http/request.php';
require_once __DIR__ . '/http/response.php';
require_once __DIR__ . '/http/redirect.php';
require_once __DIR__ . '/http/csrf.php';
require_once __DIR__ . '/auth/permissions.php';
require_once __DIR__ . '/auth/auth.php';
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/db/migrations.php';
require_once __DIR__ . '/identity/contracts.php';
require_once __DIR__ . '/identity/ids.php';
require_once __DIR__ . '/identity/users.php';
require_once __DIR__ . '/identity/auth_identities.php';
require_once __DIR__ . '/identity/contact_emails.php';
require_once __DIR__ . '/identity/session_records.php';
require_once __DIR__ . '/identity/auth_transactions.php';
require_once __DIR__ . '/identity/audit_events.php';

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
