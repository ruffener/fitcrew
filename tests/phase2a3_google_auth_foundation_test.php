<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

function fc_google_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fc_google_test_tx(PDO $pdo, string $state, string $nonce, string $browser): string
{
    return fc_auth_transaction_create($pdo, 'LOGIN', 'GOOGLE', null, $state, $browser, 'APP_HOME', $nonce)['public_id'];
}

try {
    $_ENV['PRELAUNCH_AUTH_PROOF_MODE'] = 'true';
    $_ENV['PRELAUNCH_AUTH_ALLOWED_EMAILS'] = 'proof@example.com';
    $_ENV['SESSION_IDLE_SECONDS'] = '3600';
    $_ENV['SESSION_ABSOLUTE_SECONDS'] = '86400';

    $pdo = fc_db();
    $pdo->beginTransaction();

    $baselineUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $baselineIdentities = (int) $pdo->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn();
    $baselineContacts = (int) $pdo->query('SELECT COUNT(*) FROM user_contact_emails')->fetchColumn();

    $claims = [
        'issuer' => 'https://accounts.google.com',
        'provider_subject' => 'phase2a3-proof-subject-1',
        'email_at_provider' => 'proof@example.com',
        'provider_email_verified' => 1,
        'display_name' => 'Google Proof User',
    ];

    $state1 = 'phase2a3-state-1';
    $browser1 = 'phase2a3-browser-1';
    $tx1 = fc_google_test_tx($pdo, $state1, 'phase2a3-nonce-1', $browser1);
    $first = fc_google_complete_verified_login(
        $pdo,
        $tx1,
        $state1,
        $browser1,
        $claims,
        'phase2a3-raw-session-1',
        'FitCrew test agent',
        '127.0.0.1'
    );

    fc_google_test_assert($first['new_account'] === true, 'first-time Google identity did not create a FitCrew user');
    $userId = (int) $first['user']['id'];
    $identityId = (int) $first['identity']['id'];
    fc_google_test_assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $baselineUsers + 1, 'first login user count incorrect');
    fc_google_test_assert((int) $pdo->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn() === $baselineIdentities + 1, 'first login identity count incorrect');

    $contactCount = (int) $pdo->query('SELECT COUNT(*) FROM user_contact_emails')->fetchColumn();
    fc_google_test_assert($contactCount === $baselineContacts, 'Google login automatically created a canonical contact email');

    $storedSession = $pdo->prepare('SELECT session_id_hash, revoked_at FROM user_sessions WHERE user_id = :user_id ORDER BY id DESC LIMIT 1');
    $storedSession->execute([':user_id' => $userId]);
    $sessionRow = $storedSession->fetch(PDO::FETCH_ASSOC);
    fc_google_test_assert(is_array($sessionRow), 'first login session record missing');
    fc_google_test_assert($sessionRow['session_id_hash'] !== 'phase2a3-raw-session-1', 'raw session ID was stored');
    fc_google_test_assert(hash_equals(fc_session_id_hash('phase2a3-raw-session-1'), (string) $sessionRow['session_id_hash']), 'session hash evidence incorrect');

    fc_session_revoke($pdo, 'phase2a3-raw-session-1', 'test_logout');

    $state2 = 'phase2a3-state-2';
    $browser2 = 'phase2a3-browser-2';
    $tx2 = fc_google_test_tx($pdo, $state2, 'phase2a3-nonce-2', $browser2);
    $returning = fc_google_complete_verified_login(
        $pdo,
        $tx2,
        $state2,
        $browser2,
        $claims,
        'phase2a3-raw-session-2'
    );
    fc_google_test_assert($returning['new_account'] === false, 'returning Google identity created a new account');
    fc_google_test_assert((int) $returning['user']['id'] === $userId, 'returning Google identity resolved a different user');
    fc_google_test_assert((int) $returning['identity']['id'] === $identityId, 'returning Google identity resolved a different identity');
    fc_google_test_assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $baselineUsers + 1, 'returning login duplicated user');
    fc_google_test_assert((int) $pdo->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn() === $baselineIdentities + 1, 'returning login duplicated identity');

    $otherUser = fc_user_create($pdo, 'Contact Owner');
    fc_contact_email_create($pdo, $otherUser['id'], 'proof@example.com', 'TEST', true);
    $matchingEmailClaims = $claims;
    $matchingEmailClaims['provider_subject'] = 'phase2a3-proof-subject-email-match';
    $state3 = 'phase2a3-state-3';
    $browser3 = 'phase2a3-browser-3';
    $tx3 = fc_google_test_tx($pdo, $state3, 'phase2a3-nonce-3', $browser3);
    $emailMatch = fc_google_complete_verified_login($pdo, $tx3, $state3, $browser3, $matchingEmailClaims, 'phase2a3-raw-session-3');
    fc_google_test_assert((int) $emailMatch['user']['id'] !== $otherUser['id'], 'matching email auto-merged Google identity to another user');

    $beforeDeniedUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $beforeDeniedIdentities = (int) $pdo->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn();
    $deniedClaims = $claims;
    $deniedClaims['provider_subject'] = 'phase2a3-denied-subject';
    $deniedClaims['email_at_provider'] = 'not-allowed@example.com';
    $state4 = 'phase2a3-state-4';
    $browser4 = 'phase2a3-browser-4';
    $tx4 = fc_google_test_tx($pdo, $state4, 'phase2a3-nonce-4', $browser4);
    try {
        fc_google_complete_verified_login($pdo, $tx4, $state4, $browser4, $deniedClaims, 'phase2a3-raw-session-4');
        throw new RuntimeException('prelaunch gate allowed a non-allowed new identity');
    } catch (DomainException $e) {
        fc_google_test_assert($e->getMessage() === 'prelaunch_new_account_denied', 'prelaunch denial used unexpected reason');
    }
    fc_google_test_assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $beforeDeniedUsers, 'prelaunch denial created a user');
    fc_google_test_assert((int) $pdo->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn() === $beforeDeniedIdentities, 'prelaunch denial created an identity');

    fc_user_set_account_status($pdo, $userId, 'SUSPENDED');
    $state5 = 'phase2a3-state-5';
    $browser5 = 'phase2a3-browser-5';
    $tx5 = fc_google_test_tx($pdo, $state5, 'phase2a3-nonce-5', $browser5);
    try {
        fc_google_complete_verified_login($pdo, $tx5, $state5, $browser5, $claims, 'phase2a3-raw-session-5');
        throw new RuntimeException('SUSPENDED account was allowed to authenticate');
    } catch (DomainException $e) {
        fc_google_test_assert($e->getMessage() === 'fitcrew_account_access_denied', 'SUSPENDED rejection used unexpected reason');
    }

    fc_user_set_account_status($pdo, $userId, 'DEACTIVATED');
    $state6 = 'phase2a3-state-6';
    $browser6 = 'phase2a3-browser-6';
    $tx6 = fc_google_test_tx($pdo, $state6, 'phase2a3-nonce-6', $browser6);
    try {
        fc_google_complete_verified_login($pdo, $tx6, $state6, $browser6, $claims, 'phase2a3-raw-session-6');
        throw new RuntimeException('DEACTIVATED account was allowed to authenticate');
    } catch (DomainException $e) {
        fc_google_test_assert($e->getMessage() === 'fitcrew_account_access_denied', 'DEACTIVATED rejection used unexpected reason');
    }
    fc_user_set_account_status($pdo, $userId, 'ACTIVE');

    $stateSecurity = 'phase2a3-state-security';
    $browserSecurity = 'phase2a3-browser-security';
    $txSecurity = fc_google_test_tx($pdo, $stateSecurity, 'phase2a3-nonce-security', $browserSecurity);
    fc_google_test_assert(
        fc_auth_transaction_find_valid($pdo, $txSecurity, 'LOGIN', 'GOOGLE', 'wrong-state', $browserSecurity, null) === null,
        'wrong transaction state was accepted'
    );
    fc_google_test_assert(
        fc_auth_transaction_find_valid($pdo, $txSecurity, 'LOGIN', 'GOOGLE', $stateSecurity, 'wrong-browser', null) === null,
        'wrong browser binding was accepted'
    );
    fc_google_test_assert(
        fc_auth_transaction_consume($pdo, $txSecurity, 'LOGIN', 'GOOGLE', $stateSecurity, $browserSecurity, null),
        'valid transaction could not be consumed'
    );
    fc_google_test_assert(
        fc_auth_transaction_find_valid($pdo, $txSecurity, 'LOGIN', 'GOOGLE', $stateSecurity, $browserSecurity, null) === null,
        'consumed transaction was reusable'
    );

    $stateExpired = 'phase2a3-state-expired';
    $browserExpired = 'phase2a3-browser-expired';
    $txExpired = fc_google_test_tx($pdo, $stateExpired, 'phase2a3-nonce-expired', $browserExpired);
    $expire = $pdo->prepare(
        'UPDATE auth_transactions ' .
        'SET created_at = DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 2 MINUTE), ' .
        '    expires_at = DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 MINUTE) ' .
        'WHERE public_id = :public_id'
    );
    $expire->execute([':public_id' => $txExpired]);
    fc_google_test_assert(
        fc_auth_transaction_find_valid($pdo, $txExpired, 'LOGIN', 'GOOGLE', $stateExpired, $browserExpired, null) === null,
        'expired transaction was accepted'
    );

    $_SESSION['fitcrew_csrf_token'] = 'phase2a3-csrf-good';
    fc_google_test_assert(!fc_validate_csrf('phase2a3-csrf-bad'), 'CSRF failure was accepted');

    $pdo->rollBack();

    echo "Phase 2A3 Google authentication foundation proof: PASS\n";
    echo "- first-time Google identity → one user / one identity / one session: PASS\n";
    echo "- returning Google identity → same user / same identity / new session: PASS\n";
    echo "- provider email does not auto-create contact email: PASS\n";
    echo "- matching contact email does not auto-merge users: PASS\n";
    echo "- prelaunch non-allowed identity denied with no user/identity creation: PASS\n";
    echo "- SUSPENDED account denied: PASS\n";
    echo "- DEACTIVATED account denied: PASS\n";
    echo "- raw FitCrew session ID not stored: PASS\n";
    echo "- wrong transaction state rejected: PASS\n";
    echo "- wrong browser binding rejected: PASS\n";
    echo "- consumed transaction reuse rejected: PASS\n";
    echo "- expired transaction rejected: PASS\n";
    echo "- CSRF failure rejected: PASS\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
