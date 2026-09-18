<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once fc_path('inc/auth/email_magic_link.php');

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
// Native form POSTs under no-referrer send Origin: null. Keep the origin for
// our same-origin completion POST without sending referrers to other sites.
// The bearer token stays in the fragment/form body, never in the referrer.
header('Referrer-Policy: same-origin');
$scriptNonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
header(
    "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; " .
    "script-src 'nonce-" . $scriptNonce . "'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
);
header('X-Content-Type-Options: nosniff');

if (fc_request_method() !== 'GET') {
    http_response_code(405);
    exit('Method not allowed.');
}

$csrfToken = fc_csrf_token();
require fc_path('views/auth/email_link_confirm.php');
