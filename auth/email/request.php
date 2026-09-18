<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once fc_path('inc/auth/email_magic_link.php');

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

if (!fc_is_post()) {
    http_response_code(405);
    exit('Method not allowed.');
}

if (!fc_email_magic_link_request_origin_valid($_SERVER['HTTP_ORIGIN'] ?? null)) {
    try {
        fc_email_magic_link_audit_rejection(fc_db(), 'origin_failed');
    } catch (Throwable) {
    }
    fc_email_request_require_acknowledgement();
    fc_redirect('/login.php');
}

if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
    try {
        fc_email_magic_link_audit_rejection(fc_db(), 'csrf_failed');
    } catch (Throwable) {
    }
    fc_email_request_require_acknowledgement();
    fc_redirect('/login.php');
}

try {
    fc_email_magic_link_request(
        fc_db(),
        (string) ($_POST['email'] ?? ''),
        (string) ($_SERVER['REMOTE_ADDR'] ?? '')
    );
} catch (Throwable) {
    fc_log('error', 'Email sign-in request failed unexpectedly.', [
        'reason' => 'email_magic_link_request_failure',
    ]);
}

// Enumeration resistance: every syntactically valid request path returns the
// same message regardless of identity existence, admission, rate limit, or mail result.
fc_email_request_require_acknowledgement();
fc_redirect('/login.php');
