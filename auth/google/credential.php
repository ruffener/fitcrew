<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fc_google_json_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function fc_google_record_rejection_safely(string $reason): void
{
    try {
        fc_google_audit_rejection(fc_db(), $reason);
    } catch (Throwable) {
        // Authentication rejection must remain fail-closed even when audit storage is unavailable.
    }
}

if (!fc_is_post()) {
    fc_google_json_response(405, ['ok' => false, 'message' => 'Method not allowed.']);
}

if (!fc_google_auth_enabled()) {
    fc_google_json_response(503, ['ok' => false, 'message' => 'Google sign-in is not currently available.']);
}

if (!fc_google_request_origin_valid($_SERVER['HTTP_ORIGIN'] ?? null)) {
    fc_google_record_rejection_safely('origin_failed');
    fc_google_json_response(403, ['ok' => false, 'message' => 'Google sign-in could not be completed.']);
}

if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
    fc_google_record_rejection_safely('csrf_failed');
    fc_google_json_response(403, ['ok' => false, 'message' => 'Google sign-in could not be completed.']);
}

$transactionId = trim((string) ($_POST['transaction_id'] ?? ''));
$rawState = trim((string) ($_POST['state'] ?? ''));
$credential = trim((string) ($_POST['credential'] ?? ''));
$browserBinding = fc_auth_browser_binding();

if ($transactionId === '' || $rawState === '' || $credential === '') {
    fc_google_record_rejection_safely('transaction_failed');
    fc_google_json_response(400, ['ok' => false, 'message' => 'Google sign-in could not be completed.']);
}

try {
    $pdo = fc_db();
    $transaction = fc_auth_transaction_find_valid(
        $pdo,
        $transactionId,
        'LOGIN',
        'GOOGLE',
        $rawState,
        $browserBinding,
        null
    );
    if ($transaction === null || empty($transaction['nonce_hash'])) {
        fc_google_record_rejection_safely('transaction_failed');
        fc_google_json_response(400, ['ok' => false, 'message' => 'This sign-in attempt has expired or is no longer valid.']);
    }

    $claims = fc_google_verify_id_token($credential, (string) $transaction['nonce_hash']);

    if (!session_regenerate_id(true) || session_id() === '') {
        throw new RuntimeException('Unable to establish a fresh local FitCrew session.');
    }

    $result = fc_google_complete_verified_login(
        $pdo,
        $transactionId,
        $rawState,
        $browserBinding,
        $claims,
        session_id(),
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null
    );

    fc_google_json_response(200, [
        'ok' => true,
        'redirect' => $result['destination'],
        'new_account' => (bool) $result['new_account'],
    ]);
} catch (DomainException $e) {
    $reason = match ($e->getMessage()) {
        'prelaunch_new_account_denied' => 'prelaunch_denied',
        'fitcrew_account_access_denied' => 'account_denied',
        'google_nonce_invalid' => 'nonce_failed',
        'auth_transaction_invalid', 'auth_transaction_already_consumed' => 'transaction_failed',
        default => 'credential_failed',
    };
    fc_google_record_rejection_safely($reason);

    $message = $reason === 'prelaunch_denied'
        ? 'This account is not enabled for the controlled FitCrew Challenge prelaunch proof.'
        : ($reason === 'account_denied'
            ? 'FitCrew Challenge account access is unavailable.'
            : 'Google sign-in could not be completed. Please try again.');

    fc_google_json_response(403, ['ok' => false, 'message' => $message]);
} catch (Throwable $e) {
    fc_log('error', 'Google authentication failed unexpectedly.', [
        'reason' => 'google_auth_unexpected_failure',
    ]);
    fc_google_record_rejection_safely('unexpected_failure');
    fc_google_json_response(503, ['ok' => false, 'message' => 'Google sign-in is temporarily unavailable.']);
}
