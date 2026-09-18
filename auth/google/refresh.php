<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fc_google_refresh_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function fc_google_refresh_reject(int $status, string $reason, string $message): never
{
    try {
        fc_audit_event_write(fc_db(), [
            'event_type' => 'GOOGLE_AUTH_REJECTED',
            'target_type' => 'AUTHENTICATION',
            'outcome' => 'DENIED',
            'metadata' => ['provider' => 'GOOGLE', 'operation' => 'refresh', 'reason' => $reason],
        ]);
    } catch (Throwable) {
        // A failed audit write must never permit the rejected refresh.
    }
    fc_google_refresh_json($status, ['ok' => false, 'message' => $message]);
}

if (!fc_is_post()) {
    fc_google_refresh_json(405, ['ok' => false, 'message' => 'Method not allowed.']);
}
if (!fc_google_auth_enabled()) {
    fc_google_refresh_json(503, ['ok' => false, 'message' => 'Google sign-in is not currently available.']);
}
if (!fc_google_request_origin_valid($_SERVER['HTTP_ORIGIN'] ?? null)) {
    fc_google_refresh_reject(403, 'origin_failed', 'Your sign-in page could not be verified. Reload this page and try again.');
}
if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
    fc_google_refresh_reject(403, 'csrf_failed', 'Your sign-in page could not be verified. Reload this page and try again.');
}

$transactionId = trim((string) ($_POST['transaction_id'] ?? ''));
$rawState = trim((string) ($_POST['state'] ?? ''));
if ($transactionId === '' || $rawState === '') {
    fc_google_refresh_reject(400, 'transaction_failed', 'Google sign-in could not be refreshed. Reload this page and try again.');
}

try {
    $prepared = fc_google_refresh_login_transaction(
        fc_db(),
        $transactionId,
        $rawState,
        fc_auth_browser_binding(),
        false
    );
    fc_google_refresh_json(200, [
        'ok' => true,
        'refresh_required' => true,
        'message' => 'Your sign-in page was open for a while, so we refreshed it. Please continue with Google again.',
        'google' => $prepared,
    ]);
} catch (DomainException $error) {
    if (in_array($error->getMessage(), [
        'invitation_continuation_invalid',
        'invitation_continuation_product_invalid',
    ], true)) {
        fc_auth_crew_invitation_continuation_clear_session();
        fc_google_refresh_reject(403, 'invitation_failed',
            'This Crew invitation changed or expired. Open the latest invitation email and try again.');
    }
    fc_google_refresh_reject(409, 'transaction_failed',
        'Google sign-in could not be refreshed. Please reload the page and try again.');
} catch (Throwable) {
    fc_google_refresh_reject(503, 'unexpected_failure',
        'Google sign-in is temporarily unavailable. Please try again.');
}
