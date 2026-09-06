<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

function fc_microsoft_record_rejection_safely(string $reason): void
{
    try {
        fc_microsoft_audit_rejection(fc_db(), $reason);
    } catch (Throwable) {
        // Authentication rejection remains fail-closed even if audit storage is unavailable.
    }
}

if (!fc_is_post()) {
    http_response_code(405);
    exit('Method not allowed.');
}

if (!fc_microsoft_auth_enabled()) {
    fc_flash('notice', 'Microsoft sign-in is not currently available.');
    fc_redirect('/login.php');
}

if (!fc_microsoft_request_origin_valid($_SERVER['HTTP_ORIGIN'] ?? null)) {
    fc_microsoft_record_rejection_safely('origin_failed');
    fc_flash('error', 'Microsoft sign-in could not be completed.');
    fc_redirect('/login.php');
}

$decodedState = fc_microsoft_state_decode((string) ($_POST['state'] ?? ''));
if ($decodedState === null) {
    fc_microsoft_record_rejection_safely('transaction_failed');
    fc_flash('error', 'Microsoft sign-in could not be completed. Please try again.');
    fc_redirect('/login.php');
}

$transactionId = $decodedState['transaction_id'];
$rawState = $decodedState['state'];
$browserBinding = fc_auth_browser_binding();
$providerError = (string) ($_POST['provider_error'] ?? '0') === '1';
$authorizationCode = trim((string) ($_POST['code'] ?? ''));

try {
    $pdo = fc_db();
    $transaction = fc_auth_transaction_find_valid(
        $pdo,
        $transactionId,
        'LOGIN',
        'MICROSOFT',
        $rawState,
        $browserBinding,
        null
    );
    if ($transaction === null || empty($transaction['nonce_hash'])) {
        throw new DomainException('auth_transaction_invalid');
    }

    if ($providerError) {
        fc_auth_transaction_consume($pdo, $transactionId, 'LOGIN', 'MICROSOFT', $rawState, $browserBinding, null);
        fc_microsoft_record_rejection_safely('provider_error');
        fc_flash('notice', 'Microsoft sign-in was not completed.');
        fc_redirect('/login.php');
    }

    if ($authorizationCode === '') {
        throw new DomainException('microsoft_authorization_code_invalid');
    }

    $pkceVerifier = fc_auth_transaction_recover_pkce_verifier(
        $pdo,
        $transactionId,
        'LOGIN',
        'MICROSOFT',
        $rawState,
        $browserBinding,
        null
    );
    if ($pkceVerifier === null) {
        throw new DomainException('microsoft_pkce_unavailable');
    }

    $rawIdToken = fc_microsoft_exchange_authorization_code($authorizationCode, $pkceVerifier);
    $claims = fc_microsoft_verify_id_token($rawIdToken, (string) $transaction['nonce_hash']);

    // Drop transient provider secrets as soon as validation is complete.
    $authorizationCode = '';
    $pkceVerifier = '';
    $rawIdToken = '';

    if (!session_regenerate_id(true) || session_id() === '') {
        throw new RuntimeException('Unable to establish a fresh local FitCrew session.');
    }

    $result = fc_microsoft_complete_verified_login(
        $pdo,
        $transactionId,
        $rawState,
        $browserBinding,
        $claims,
        session_id(),
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null
    );

    fc_redirect($result['destination']);
} catch (DomainException $e) {
    $reason = match ($e->getMessage()) {
        'prelaunch_new_account_denied' => 'prelaunch_denied',
        'fitcrew_account_access_denied' => 'account_denied',
        'microsoft_nonce_invalid' => 'nonce_failed',
        'microsoft_issuer_invalid', 'microsoft_signing_key_issuer_invalid' => 'issuer_failed',
        'microsoft_tid_invalid', 'microsoft_oid_invalid', 'microsoft_identity_issuer_mismatch', 'microsoft_identity_subject_mismatch' => 'identity_failed',
        'microsoft_pkce_unavailable' => 'pkce_failed',
        'microsoft_code_exchange_invalid_client' => 'code_exchange_invalid_client',
        'microsoft_code_exchange_invalid_grant' => 'code_exchange_invalid_grant',
        'microsoft_code_exchange_invalid_scope' => 'code_exchange_invalid_scope',
        'microsoft_code_exchange_unauthorized_client' => 'code_exchange_unauthorized_client',
        'microsoft_code_exchange_provider_unavailable' => 'code_exchange_provider_unavailable',
        'microsoft_code_exchange_transport_failed' => 'code_exchange_transport_failed',
        'microsoft_code_exchange_response_invalid' => 'code_exchange_response_invalid',
        'microsoft_code_exchange_failed', 'microsoft_authorization_code_invalid' => 'code_exchange_failed',
        'auth_transaction_invalid', 'auth_transaction_already_consumed' => 'transaction_failed',
        default => 'token_failed',
    };
    fc_microsoft_record_rejection_safely($reason);

    $message = $reason === 'prelaunch_denied'
        ? 'This Microsoft identity is not enabled for the controlled FitCrew Challenge prelaunch proof.'
        : ($reason === 'account_denied'
            ? 'FitCrew Challenge account access is unavailable.'
            : 'Microsoft sign-in could not be completed. Please try again.');

    fc_flash('error', $message);
    fc_redirect('/login.php');
} catch (Throwable) {
    fc_log('error', 'Microsoft authentication failed unexpectedly.', [
        'reason' => 'microsoft_auth_unexpected_failure',
    ]);
    fc_microsoft_record_rejection_safely('unexpected_failure');
    fc_flash('error', 'Microsoft sign-in is temporarily unavailable. Please try again.');
    fc_redirect('/login.php');
}
