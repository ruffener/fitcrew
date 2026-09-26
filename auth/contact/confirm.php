<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
if (fc_request_method() !== 'GET') { http_response_code(405); exit('Method not allowed.'); }
$csrf = fc_csrf_token();
require fc_path('views/auth/contact_confirm.php');
