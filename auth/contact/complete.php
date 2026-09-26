<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once fc_path('inc/auth/user_operations.php');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('Content-Type: text/plain; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
if (!fc_is_post()) { http_response_code(405); exit('Method not allowed.'); }
if (array_diff(array_keys($_POST), ['csrf_token', 'token', 'confirm']) !== []) { http_response_code(400); exit('Unexpected form fields.'); }
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$app = parse_url((string) fc_config()['url']);
$expected = strtolower(($app['scheme'] ?? '') . '://' . ($app['host'] ?? '') . (isset($app['port']) ? ':' . $app['port'] : ''));
if (($origin !== null && $origin !== '' && strtolower(rtrim($origin, '/')) !== $expected)
    || !fc_validate_csrf(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)
    || ($_POST['confirm'] ?? null) !== 'yes') {
    http_response_code(403); exit('Confirmation could not be accepted. Reopen the email link.');
}
try {
    $limit = fc_rate_limit_consume(fc_db(), 'auth.contact.complete', (string) ($_SERVER['REMOTE_ADDR'] ?? fc_auth_browser_binding()), 20, 900);
    if (!$limit['allowed']) { http_response_code(429); exit('Please wait before trying again.'); }
    fc_auth_user_contact_verification_complete(fc_db(), is_string($_POST['token'] ?? null) ? $_POST['token'] : '');
    echo 'Your contact email is verified. You may close this page. Your primary contact has not changed.';
} catch (DomainException $e) {
    http_response_code(409);
    echo $e->getMessage() === 'account_reconciliation_required'
        ? 'ACCOUNT RECONCILIATION REQUIRED. No address was transferred and no accounts were merged.'
        : 'This contact verification is unavailable, expired, replaced, or affected by an account change. Request a new link.';
} catch (Throwable) {
    http_response_code(503); echo 'Contact verification is temporarily unavailable. Please try again later.';
}
