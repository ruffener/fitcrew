<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

function fc_ms_foundation_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{tx:string,state:string,nonce:string,verifier:string,browser:string} */
function fc_ms_foundation_tx(PDO $pdo, string $suffix): array
{
    $state = 'phase2a4-state-' . $suffix;
    $nonce = 'phase2a4-nonce-' . $suffix;
    $browser = 'phase2a4-browser-' . $suffix;
    $verifier = fc_base64url_encode(hash('sha512', 'phase2a4-verifier-' . $suffix, true));
    $tx = fc_auth_transaction_create(
        $pdo,
        'LOGIN',
        'MICROSOFT',
        null,
        $state,
        $browser,
        'APP_HOME',
        $nonce,
        $verifier
    );

    return [
        'tx' => $tx['public_id'],
        'state' => $state,
        'nonce' => $nonce,
        'verifier' => $verifier,
        'browser' => $browser,
    ];
}

try {
    $_ENV['PRELAUNCH_AUTH_PROOF_MODE'] = 'true';
    $_ENV['SESSION_IDLE_SECONDS'] = '3600';
    $_ENV['SESSION_ABSOLUTE_SECONDS'] = '86400';

    $tenant1 = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    $object1 = '11111111-aaaa-4bbb-8ccc-222222222222';
    $tenant2 = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';
    $object2 = '33333333-bbbb-4ccc-8ddd-444444444444';
    $tenantCollision = 'cccccccc-dddd-4eee-8fff-aaaaaaaaaaaa';
    $objectCollision = '55555555-cccc-4ddd-8eee-666666666666';
    $_ENV['PRELAUNCH_MICROSOFT_ALLOWED_IDENTITIES'] = implode(',', [
        $tenant1 . ':' . $object1,
        $tenant2 . ':' . $object2,
        $tenantCollision . ':' . $objectCollision,
    ]);

    $pdo = fc_db();
    $pdo->beginTransaction();

    $baselineUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $baselineIdentities = (int) $pdo->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn();
    $baselineContacts = (int) $pdo->query('SELECT COUNT(*) FROM user_contact_emails')->fetchColumn();

    $claims = [
        'issuer' => fc_microsoft_expected_issuer($tenant1),
        'provider_tenant_id' => $tenant1,
        'provider_object_id' => $object1,
        'protocol_subject' => 'phase2a4-protocol-subject-1',
        'email_at_provider' => 'shared-proof@example.com',
        'provider_email_verified' => null,
        'display_name' => 'Microsoft Proof User',
    ];

    $tx1 = fc_ms_foundation_tx($pdo, 'first');
    fc_ms_foundation_assert(
        hash_equals(
            $tx1['verifier'],
            (string) fc_auth_transaction_recover_pkce_verifier(
                $pdo,
                $tx1['tx'],
                'LOGIN',
                'MICROSOFT',
                $tx1['state'],
                $tx1['browser'],
                null
            )
        ),
        'Microsoft PKCE verifier did not recover through the protected envelope'
    );

    $pkceStored = $pdo->prepare('SELECT pkce_verifier_hash, pkce_verifier_secret_envelope FROM auth_transactions WHERE public_id = :public_id');
    $pkceStored->execute([':public_id' => $tx1['tx']]);
    $pkceRow = $pkceStored->fetch(PDO::FETCH_ASSOC);
    fc_ms_foundation_assert(is_array($pkceRow), 'Microsoft PKCE transaction evidence missing');
    fc_ms_foundation_assert((string) $pkceRow['pkce_verifier_hash'] !== $tx1['verifier'], 'raw Microsoft PKCE verifier was stored in hash field');
    fc_ms_foundation_assert((string) $pkceRow['pkce_verifier_secret_envelope'] !== $tx1['verifier'], 'raw Microsoft PKCE verifier was stored in envelope field');

    $first = fc_microsoft_complete_verified_login(
        $pdo,
        $tx1['tx'],
        $tx1['state'],
        $tx1['browser'],
        $claims,
        'phase2a4-raw-session-1',
        'FitCrew Microsoft test agent',
        '127.0.0.1'
    );

    fc_ms_foundation_assert($first['new_account'] === true, 'first-time Microsoft identity did not create a FitCrew user');
    $userId = (int) $first['user']['id'];
    $identityId = (int) $first['identity']['id'];
    fc_ms_foundation_assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $baselineUsers + 1, 'first Microsoft login user count incorrect');
    fc_ms_foundation_assert((int) $pdo->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn() === $baselineIdentities + 1, 'first Microsoft login identity count incorrect');
    fc_ms_foundation_assert((int) $pdo->query('SELECT COUNT(*) FROM user_contact_emails')->fetchColumn() === $baselineContacts, 'Microsoft login automatically created a canonical contact email');

    $identityStmt = $pdo->prepare('SELECT provider_key, provider_tenant_id, provider_object_id, protocol_subject, provider_email_verified FROM user_auth_identities WHERE id = :id');
    $identityStmt->execute([':id' => $identityId]);
    $identityRow = $identityStmt->fetch(PDO::FETCH_ASSOC);
    fc_ms_foundation_assert(is_array($identityRow), 'Microsoft identity row missing');
    fc_ms_foundation_assert((string) $identityRow['provider_key'] === 'MICROSOFT', 'Microsoft identity provider key incorrect');
    fc_ms_foundation_assert((string) $identityRow['provider_tenant_id'] === $tenant1, 'Microsoft tenant identity incorrect');
    fc_ms_foundation_assert((string) $identityRow['provider_object_id'] === $object1, 'Microsoft object identity incorrect');
    fc_ms_foundation_assert($identityRow['provider_email_verified'] === null, 'Microsoft provider email verification was not UNKNOWN/NULL');

    $sessionStmt = $pdo->prepare('SELECT session_id_hash, revoked_at FROM user_sessions WHERE user_id = :user_id ORDER BY id DESC LIMIT 1');
    $sessionStmt->execute([':user_id' => $userId]);
    $sessionRow = $sessionStmt->fetch(PDO::FETCH_ASSOC);
    fc_ms_foundation_assert(is_array($sessionRow), 'first Microsoft session record missing');
    fc_ms_foundation_assert((string) $sessionRow['session_id_hash'] !== 'phase2a4-raw-session-1', 'raw FitCrew session ID was stored');
    fc_ms_foundation_assert(hash_equals(fc_session_id_hash('phase2a4-raw-session-1'), (string) $sessionRow['session_id_hash']), 'FitCrew session hash evidence incorrect');

    fc_session_revoke($pdo, 'phase2a4-raw-session-1', 'test_logout');
    $tx2 = fc_ms_foundation_tx($pdo, 'returning');
    $returning = fc_microsoft_complete_verified_login(
        $pdo,
        $tx2['tx'],
        $tx2['state'],
        $tx2['browser'],
        $claims,
        'phase2a4-raw-session-2'
    );
    fc_ms_foundation_assert($returning['new_account'] === false, 'returning Microsoft identity created a new account');
    fc_ms_foundation_assert((int) $returning['user']['id'] === $userId, 'returning Microsoft identity resolved a different user');
    fc_ms_foundation_assert((int) $returning['identity']['id'] === $identityId, 'returning Microsoft identity resolved a different identity');
    fc_ms_foundation_assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $baselineUsers + 1, 'returning Microsoft login duplicated user');
    fc_ms_foundation_assert((int) $pdo->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn() === $baselineIdentities + 1, 'returning Microsoft login duplicated identity');

    // Cross-provider matching-email proof: an existing Google identity with the same descriptive
    // provider email must not absorb the independent Microsoft tenant/object identity.
    $googleUser = fc_user_create($pdo, 'Google Collision User');
    $googleIdentity = fc_auth_identity_create($pdo, $googleUser['id'], [
        'provider_key' => 'GOOGLE',
        'issuer' => 'https://accounts.google.com',
        'provider_subject' => 'phase2a4-google-collision-subject',
        'email_at_provider' => 'collision@example.com',
        'provider_email_verified' => 1,
        'identity_status' => 'ACTIVE',
    ]);
    $contactsBeforeCollision = (int) $pdo->query('SELECT COUNT(*) FROM user_contact_emails')->fetchColumn();
    $collisionClaims = [
        'issuer' => fc_microsoft_expected_issuer($tenantCollision),
        'provider_tenant_id' => $tenantCollision,
        'provider_object_id' => $objectCollision,
        'protocol_subject' => 'phase2a4-microsoft-collision-subject',
        'email_at_provider' => 'collision@example.com',
        'provider_email_verified' => null,
        'display_name' => 'Microsoft Collision User',
    ];
    $txCollision = fc_ms_foundation_tx($pdo, 'collision');
    $collision = fc_microsoft_complete_verified_login(
        $pdo,
        $txCollision['tx'],
        $txCollision['state'],
        $txCollision['browser'],
        $collisionClaims,
        'phase2a4-raw-session-collision'
    );
    fc_ms_foundation_assert((int) $collision['user']['id'] !== (int) $googleUser['id'], 'matching provider email auto-merged Microsoft identity into Google user');
    fc_ms_foundation_assert((int) $collision['identity']['id'] !== (int) $googleIdentity['id'], 'matching provider email replaced/linked Google identity');
    fc_ms_foundation_assert((int) $pdo->query('SELECT COUNT(*) FROM user_contact_emails')->fetchColumn() === $contactsBeforeCollision, 'cross-provider collision created a contact-email ownership claim');

    $beforeDeniedUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $beforeDeniedIdentities = (int) $pdo->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn();
    $deniedClaims = $claims;
    $deniedClaims['provider_object_id'] = '77777777-dddd-4eee-8fff-888888888888';
    $deniedClaims['protocol_subject'] = 'phase2a4-denied-subject';
    $txDenied = fc_ms_foundation_tx($pdo, 'denied');
    try {
        fc_microsoft_complete_verified_login(
            $pdo,
            $txDenied['tx'],
            $txDenied['state'],
            $txDenied['browser'],
            $deniedClaims,
            'phase2a4-raw-session-denied'
        );
        throw new RuntimeException('prelaunch Microsoft gate allowed a non-allowlisted new identity');
    } catch (DomainException $e) {
        fc_ms_foundation_assert($e->getMessage() === 'prelaunch_new_account_denied', 'Microsoft prelaunch denial used unexpected reason');
    }
    fc_ms_foundation_assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $beforeDeniedUsers, 'Microsoft prelaunch denial created a user');
    fc_ms_foundation_assert((int) $pdo->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn() === $beforeDeniedIdentities, 'Microsoft prelaunch denial created an identity');

    fc_user_set_account_status($pdo, $userId, 'SUSPENDED');
    $txSuspended = fc_ms_foundation_tx($pdo, 'suspended');
    try {
        fc_microsoft_complete_verified_login($pdo, $txSuspended['tx'], $txSuspended['state'], $txSuspended['browser'], $claims, 'phase2a4-raw-session-suspended');
        throw new RuntimeException('SUSPENDED Microsoft account was allowed to authenticate');
    } catch (DomainException $e) {
        fc_ms_foundation_assert($e->getMessage() === 'fitcrew_account_access_denied', 'SUSPENDED Microsoft rejection used unexpected reason');
    }

    fc_user_set_account_status($pdo, $userId, 'DEACTIVATED');
    $txDeactivated = fc_ms_foundation_tx($pdo, 'deactivated');
    try {
        fc_microsoft_complete_verified_login($pdo, $txDeactivated['tx'], $txDeactivated['state'], $txDeactivated['browser'], $claims, 'phase2a4-raw-session-deactivated');
        throw new RuntimeException('DEACTIVATED Microsoft account was allowed to authenticate');
    } catch (DomainException $e) {
        fc_ms_foundation_assert($e->getMessage() === 'fitcrew_account_access_denied', 'DEACTIVATED Microsoft rejection used unexpected reason');
    }
    fc_user_set_account_status($pdo, $userId, 'ACTIVE');

    $disableIdentity = $pdo->prepare('UPDATE user_auth_identities SET identity_status = :status WHERE id = :id');
    $disableIdentity->execute([':status' => 'DISABLED', ':id' => $identityId]);
    $txDisabled = fc_ms_foundation_tx($pdo, 'disabled-identity');
    try {
        fc_microsoft_complete_verified_login($pdo, $txDisabled['tx'], $txDisabled['state'], $txDisabled['browser'], $claims, 'phase2a4-raw-session-disabled');
        throw new RuntimeException('DISABLED Microsoft identity was allowed to authenticate');
    } catch (DomainException $e) {
        fc_ms_foundation_assert($e->getMessage() === 'fitcrew_account_access_denied', 'DISABLED Microsoft identity rejection used unexpected reason');
    }
    $disableIdentity->execute([':status' => 'ACTIVE', ':id' => $identityId]);

    $txSecurity = fc_ms_foundation_tx($pdo, 'security');
    fc_ms_foundation_assert(
        fc_auth_transaction_find_valid($pdo, $txSecurity['tx'], 'LOGIN', 'MICROSOFT', 'wrong-state', $txSecurity['browser'], null) === null,
        'wrong Microsoft transaction state was accepted'
    );
    fc_ms_foundation_assert(
        fc_auth_transaction_find_valid($pdo, $txSecurity['tx'], 'LOGIN', 'MICROSOFT', $txSecurity['state'], 'wrong-browser', null) === null,
        'wrong Microsoft browser binding was accepted'
    );
    fc_ms_foundation_assert(
        fc_auth_transaction_consume($pdo, $txSecurity['tx'], 'LOGIN', 'MICROSOFT', $txSecurity['state'], $txSecurity['browser'], null),
        'valid Microsoft transaction could not be consumed'
    );
    fc_ms_foundation_assert(
        fc_auth_transaction_find_valid($pdo, $txSecurity['tx'], 'LOGIN', 'MICROSOFT', $txSecurity['state'], $txSecurity['browser'], null) === null,
        'consumed Microsoft transaction was reusable'
    );
    fc_ms_foundation_assert(
        fc_auth_transaction_recover_pkce_verifier($pdo, $txSecurity['tx'], 'LOGIN', 'MICROSOFT', $txSecurity['state'], $txSecurity['browser'], null) === null,
        'consumed Microsoft transaction retained recoverable PKCE verifier'
    );

    $txExpired = fc_ms_foundation_tx($pdo, 'expired');
    $expire = $pdo->prepare(
        'UPDATE auth_transactions ' .
        'SET created_at = DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 2 MINUTE), ' .
        '    expires_at = DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 MINUTE) ' .
        'WHERE public_id = :public_id'
    );
    $expire->execute([':public_id' => $txExpired['tx']]);
    fc_ms_foundation_assert(
        fc_auth_transaction_find_valid($pdo, $txExpired['tx'], 'LOGIN', 'MICROSOFT', $txExpired['state'], $txExpired['browser'], null) === null,
        'expired Microsoft transaction was accepted'
    );
    fc_ms_foundation_assert(
        fc_auth_transaction_recover_pkce_verifier($pdo, $txExpired['tx'], 'LOGIN', 'MICROSOFT', $txExpired['state'], $txExpired['browser'], null) === null,
        'expired Microsoft transaction retained recoverable PKCE verifier'
    );

    $txWrongHash = fc_ms_foundation_tx($pdo, 'wrong-pkce-hash');
    $wrongHash = $pdo->prepare('UPDATE auth_transactions SET pkce_verifier_hash = :hash WHERE public_id = :public_id');
    $wrongHash->execute([
        ':hash' => fc_secret_evidence_hash('different-verifier'),
        ':public_id' => $txWrongHash['tx'],
    ]);
    try {
        fc_auth_transaction_recover_pkce_verifier($pdo, $txWrongHash['tx'], 'LOGIN', 'MICROSOFT', $txWrongHash['state'], $txWrongHash['browser'], null);
        throw new RuntimeException('wrong Microsoft PKCE verifier evidence was accepted');
    } catch (RuntimeException $e) {
        fc_ms_foundation_assert(str_contains($e->getMessage(), 'integrity check failed'), 'wrong PKCE verifier evidence used unexpected failure');
    }

    $txCorrupt = fc_ms_foundation_tx($pdo, 'corrupt-pkce');
    $corrupt = $pdo->prepare('UPDATE auth_transactions SET pkce_verifier_secret_envelope = :envelope WHERE public_id = :public_id');
    $corrupt->execute([':envelope' => 'v1.corrupted-envelope', ':public_id' => $txCorrupt['tx']]);
    $corruptRejected = false;
    try {
        fc_auth_transaction_recover_pkce_verifier($pdo, $txCorrupt['tx'], 'LOGIN', 'MICROSOFT', $txCorrupt['state'], $txCorrupt['browser'], null);
    } catch (RuntimeException) {
        $corruptRejected = true;
    }
    fc_ms_foundation_assert($corruptRejected, 'corrupted Microsoft PKCE envelope was accepted');

    $_SESSION['fitcrew_csrf_token'] = 'phase2a4-csrf-good';
    fc_ms_foundation_assert(!fc_validate_csrf('phase2a4-csrf-bad'), 'Microsoft CSRF failure was accepted');

    $pdo->rollBack();

    echo "Phase 2A4 Microsoft authentication foundation proof: PASS\n";
    echo "- first-time Microsoft tid+oid → one user / one identity / one session: PASS\n";
    echo "- returning Microsoft tid+oid → same user / same identity / new session: PASS\n";
    echo "- provider email remains descriptive / verification UNKNOWN: PASS\n";
    echo "- Microsoft provider email does not create contact email: PASS\n";
    echo "- Google + Microsoft matching email does not auto-merge/link: PASS\n";
    echo "- prelaunch non-allowlisted tid+oid denied with no user/identity creation: PASS\n";
    echo "- SUSPENDED account denied: PASS\n";
    echo "- DEACTIVATED account denied: PASS\n";
    echo "- DISABLED Microsoft identity denied: PASS\n";
    echo "- raw FitCrew session ID not stored / hash evidence correct: PASS\n";
    echo "- wrong state / wrong browser binding rejected: PASS\n";
    echo "- consumed / expired transaction rejected and PKCE verifier unavailable: PASS\n";
    echo "- wrong PKCE verifier evidence rejected: PASS\n";
    echo "- corrupted protected PKCE envelope rejected: PASS\n";
    echo "- CSRF failure rejected: PASS\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
