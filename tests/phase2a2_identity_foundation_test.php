<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';

function fc_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fc_test_expect_pdo_failure(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException $error) {
        return;
    }

    throw new RuntimeException($message);
}

function fc_test_expect_failure(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        return;
    }

    throw new RuntimeException($message);
}

if (!class_exists(Symfony\Component\Uid\Ulid::class)) {
    fwrite(STDERR, "[FAIL] symfony/uid is not installed. Run: composer update symfony/uid --with-dependencies\n");
    exit(2);
}

$phase2a2TestSecretKey = base64_encode(str_repeat("\x42", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$_ENV['AUTH_TRANSACTION_SECRET_KEY_B64'] = $phase2a2TestSecretKey;
putenv('AUTH_TRANSACTION_SECRET_KEY_B64=' . $phase2a2TestSecretKey);

$pdo = fc_db();
$dbTimezone = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
if ($dbTimezone !== '+00:00') {
    fwrite(STDERR, sprintf('[FAIL] Expected DB session timezone +00:00, got %s%s', $dbTimezone, PHP_EOL));
    exit(1);
}

$pdo->beginTransaction();

try {
    $expectedTables = [
        'users',
        'user_auth_identities',
        'user_contact_emails',
        'user_sessions',
        'auth_transactions',
        'audit_events',
    ];
    $tableStatement = $pdo->query(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()"
    );
    $tables = array_map('strval', array_column($tableStatement->fetchAll(), 'table_name'));
    foreach ($expectedTables as $table) {
        fc_test_assert(in_array($table, $tables, true), sprintf('Expected Phase 2A2 table is missing: %s', $table));
    }
    // Authorized product tables may coexist with the identity foundation. Keep this
    // boundary focused on legacy names and downstream runtimes that remain held.
    foreach (['groups', 'group_members', 'health_provider_connections', 'raw_health_imports', 'official_daily_logs'] as $forbidden) {
        fc_test_assert(!in_array($forbidden, $tables, true), sprintf('Unauthorized table exists: %s', $forbidden));
    }

    $user1 = fc_user_create($pdo, 'Phase 2A2 User One');
    $user2 = fc_user_create($pdo, 'Phase 2A2 User Two');
    fc_test_assert($user1['public_id'] !== $user2['public_id'], 'Public IDs must be unique.');
    fc_test_assert(fc_public_id_is_valid($user1['public_id']), 'User public ID must be a valid ULID.');

    fc_test_expect_pdo_failure(function () use ($pdo, $user1): void {
        $statement = $pdo->prepare(
            "INSERT INTO users (public_id, account_status, platform_role_code) VALUES (:public_id, 'ACTIVE', 'USER')"
        );
        $statement->execute([':public_id' => $user1['public_id']]);
    }, 'Duplicate public ID must be rejected.');

    fc_test_expect_pdo_failure(function () use ($pdo): void {
        $statement = $pdo->prepare(
            "INSERT INTO users (public_id, account_status, platform_role_code) VALUES (:public_id, 'INVALID', 'USER')"
        );
        $statement->execute([':public_id' => fc_new_public_id()]);
    }, 'Invalid account status must be rejected.');

    fc_test_expect_pdo_failure(function () use ($pdo): void {
        $statement = $pdo->prepare(
            "INSERT INTO users (public_id, account_status, platform_role_code) VALUES (:public_id, 'ACTIVE', 'OWNER')"
        );
        $statement->execute([':public_id' => fc_new_public_id()]);
    }, 'Invalid platform role must be rejected.');

    $google = fc_auth_identity_create($pdo, $user1['id'], [
        'provider_key' => 'GOOGLE',
        'issuer' => 'https://accounts.google.com',
        'provider_subject' => 'phase2a2-google-subject',
        'email_at_provider' => 'shared-provider@example.test',
    ]);
    fc_auth_identity_create($pdo, $user1['id'], [
        'provider_key' => 'APPLE',
        'issuer' => 'https://appleid.apple.com',
        'provider_subject' => 'phase2a2-apple-subject',
        'email_at_provider' => 'shared-provider@example.test',
    ]);

    fc_test_expect_pdo_failure(function () use ($pdo, $user2): void {
        fc_auth_identity_create($pdo, $user2['id'], [
            'provider_key' => 'GOOGLE',
            'issuer' => 'https://accounts.google.com',
            'provider_subject' => 'phase2a2-google-subject',
            'email_at_provider' => 'different@example.test',
        ]);
    }, 'One Google/Apple external identity must not belong to two users.');

    $microsoftUser2 = fc_auth_identity_create($pdo, $user2['id'], [
        'provider_key' => 'MICROSOFT',
        'issuer' => 'https://login.microsoftonline.com/test/v2.0',
        'provider_tenant_id' => 'tenant-phase2a2',
        'provider_object_id' => 'object-phase2a2',
        'protocol_subject' => 'optional-microsoft-subject',
        'email_at_provider' => 'shared-provider@example.test',
    ]);
    fc_test_expect_pdo_failure(function () use ($pdo, $user1): void {
        fc_auth_identity_create($pdo, $user1['id'], [
            'provider_key' => 'MICROSOFT',
            'issuer' => 'https://login.microsoftonline.com/test/v2.0',
            'provider_tenant_id' => 'tenant-phase2a2',
            'provider_object_id' => 'object-phase2a2',
        ]);
    }, 'Microsoft tenant + object identity must be globally unique.');

    $triTrue = fc_auth_identity_create($pdo, $user1['id'], [
        'provider_key' => 'GOOGLE',
        'issuer' => 'https://accounts.google.com',
        'provider_subject' => 'phase2a2-email-verified-true',
        'email_at_provider' => 'tri-state@example.test',
        'provider_email_verified' => true,
        'email_verification_observed_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ]);
    $triFalse = fc_auth_identity_create($pdo, $user1['id'], [
        'provider_key' => 'APPLE',
        'issuer' => 'https://appleid.apple.com',
        'provider_subject' => 'phase2a2-email-verified-false',
        'email_at_provider' => 'tri-state@example.test',
        'provider_email_verified' => false,
        'email_verification_observed_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ]);
    $triUnknown = fc_auth_identity_create($pdo, $user2['id'], [
        'provider_key' => 'GOOGLE',
        'issuer' => 'https://accounts.google.com',
        'provider_subject' => 'phase2a2-email-verified-unknown',
        'email_at_provider' => 'tri-state@example.test',
        'provider_email_verified' => null,
    ]);
    $triStatement = $pdo->prepare('SELECT provider_email_verified FROM user_auth_identities WHERE id = :id');
    $triStatement->execute([':id' => $triTrue['id']]);
    fc_test_assert((int) $triStatement->fetchColumn() === 1, 'Provider email verification TRUE must be preserved.');
    $triStatement->execute([':id' => $triFalse['id']]);
    fc_test_assert((int) $triStatement->fetchColumn() === 0, 'Provider email verification FALSE must be preserved.');
    $triStatement->execute([':id' => $triUnknown['id']]);
    fc_test_assert($triStatement->fetchColumn() === null, 'Provider email verification UNKNOWN must remain NULL.');

    $canonical = fc_contact_email_canonicalize('  Foo.Bar+tag@Example.COM  ');
    fc_test_assert($canonical === 'foo.bar+tag@example.com', 'Contact email canonicalization must trim/lowercase without dot/plus rewriting.');

    $contact1 = fc_contact_email_create(
        $pdo,
        $user1['id'],
        'Shared.Contact@Example.test',
        'USER',
        true
    );
    $contact2 = fc_contact_email_create(
        $pdo,
        $user2['id'],
        'shared.contact@example.test',
        'USER',
        true
    );
    fc_test_assert(
        $contact1['email_canonical'] === $contact2['email_canonical'],
        'Same contact email must be permitted for different users.'
    );

    fc_test_expect_pdo_failure(function () use ($pdo, $user1): void {
        fc_contact_email_create($pdo, $user1['id'], 'second-primary@example.test', 'USER', true);
    }, 'A user must not have two active primary contact emails.');

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    fc_test_expect_failure(function () use ($pdo, $user1, $microsoftUser2, $now): void {
        fc_session_record_create(
            $pdo,
            $user1['id'],
            $microsoftUser2['id'],
            'phase2a2-helper-mismatch-' . bin2hex(random_bytes(16)),
            $now->modify('+20 minutes'),
            $now->modify('+8 hours')
        );
    }, 'Session helper must reject an authentication identity owned by another user.');

    fc_test_expect_pdo_failure(function () use ($pdo, $user1, $microsoftUser2, $now): void {
        $statement = $pdo->prepare(
            'INSERT INTO user_sessions (user_id, auth_identity_id, session_id_hash, idle_expires_at, absolute_expires_at) ' .
            'VALUES (:user_id, :identity_id, :session_hash, :idle_expires_at, :absolute_expires_at)'
        );
        $statement->execute([
            ':user_id' => $user1['id'],
            ':identity_id' => $microsoftUser2['id'],
            ':session_hash' => fc_session_id_hash('phase2a2-db-mismatch-' . bin2hex(random_bytes(16))),
            ':idle_expires_at' => $now->modify('+20 minutes')->format('Y-m-d H:i:s.u'),
            ':absolute_expires_at' => $now->modify('+8 hours')->format('Y-m-d H:i:s.u'),
        ]);
    }, 'Database must reject cross-user session/auth-identity mismatch.');

    $sessionRaw = 'phase2a2-raw-session-' . bin2hex(random_bytes(16));
    $session = fc_session_record_create(
        $pdo,
        $user1['id'],
        $google['id'],
        $sessionRaw,
        $now->modify('+20 minutes'),
        $now->modify('+8 hours'),
        'Phase2A2 test agent',
        '127.0.0.1'
    );
    $storedSession = $pdo->prepare('SELECT session_id_hash FROM user_sessions WHERE id = :id');
    $storedSession->execute([':id' => $session['id']]);
    $storedHash = (string) $storedSession->fetchColumn();
    fc_test_assert($storedHash === fc_session_id_hash($sessionRaw), 'Stored session hash must match security evidence hash.');
    fc_test_assert($storedHash !== $sessionRaw, 'Raw session ID must never be stored.');
    fc_test_assert(fc_session_revoke($pdo, $sessionRaw, 'Phase 2A2 proof'), 'Individual session revocation must succeed.');

    $secondRaw = 'phase2a2-second-session-' . bin2hex(random_bytes(16));
    fc_session_record_create(
        $pdo,
        $user1['id'],
        $google['id'],
        $secondRaw,
        $now->modify('+20 minutes'),
        $now->modify('+8 hours')
    );
    fc_test_assert(fc_session_revoke_all_for_user($pdo, $user1['id'], 'Phase 2A2 all-session proof') >= 1, 'All-session revocation must revoke active sessions.');

    $state = 'phase2a2-state-' . bin2hex(random_bytes(16));
    $binding = 'phase2a2-browser-' . bin2hex(random_bytes(16));
    $nonce = 'phase2a2-nonce-' . bin2hex(random_bytes(16));
    $pkce = 'phase2a2-pkce-' . bin2hex(random_bytes(24));
    $transaction = fc_auth_transaction_create(
        $pdo,
        'LOGIN',
        'GOOGLE',
        null,
        $state,
        $binding,
        'APP_HOME',
        $nonce,
        $pkce,
        300
    );

    fc_test_assert(fc_public_id_is_valid($transaction['public_id']), 'Auth transaction public ID must be a valid ULID.');

    $txQuery = $pdo->prepare(
        'SELECT state_hash, nonce_hash, pkce_verifier_hash, pkce_verifier_secret_envelope, browser_session_binding_hash, post_auth_destination_key FROM auth_transactions WHERE id = :id'
    );
    $txQuery->execute([':id' => $transaction['id']]);
    $txRow = $txQuery->fetch();
    fc_test_assert($txRow !== false, 'Auth transaction must be stored.');
    foreach ([$state, $nonce, $pkce, $binding] as $rawSecret) {
        fc_test_assert(!in_array($rawSecret, $txRow, true), 'Raw auth transaction secrets must not be stored.');
    }
    fc_test_assert($txRow['post_auth_destination_key'] === 'APP_HOME', 'Auth transaction must store an approved destination key, not a URL.');
    fc_test_assert(!empty($txRow['pkce_verifier_secret_envelope']), 'Recoverable PKCE verifier must have a protected server-side representation.');
    fc_test_assert(!str_contains((string) $txRow['pkce_verifier_secret_envelope'], $pkce), 'Protected PKCE envelope must not expose the raw verifier.');
    fc_test_assert(
        fc_auth_transaction_recover_pkce_verifier($pdo, $transaction['public_id'], 'LOGIN', 'GOOGLE', $state, $binding, null) === $pkce,
        'Valid unexpired transaction must recover the exact PKCE verifier.'
    );
    fc_test_assert(
        fc_auth_transaction_recover_pkce_verifier($pdo, $transaction['public_id'], 'LOGIN', 'GOOGLE', $state, 'wrong-binding', null) === null,
        'Wrong browser/session binding must not recover PKCE verifier.'
    );
    fc_test_assert(
        !fc_auth_transaction_consume($pdo, $transaction['public_id'], 'LOGIN', 'APPLE', $state, $binding, null),
        'Wrong provider must not consume transaction.'
    );
    fc_test_assert(
        fc_auth_transaction_consume($pdo, $transaction['public_id'], 'LOGIN', 'GOOGLE', $state, $binding, null),
        'Correct provider/intent/state/binding must consume transaction.'
    );
    fc_test_assert(
        !fc_auth_transaction_consume($pdo, $transaction['public_id'], 'LOGIN', 'GOOGLE', $state, $binding, null),
        'Consumed transaction must be single-use.'
    );
    fc_test_assert(
        fc_auth_transaction_recover_pkce_verifier($pdo, $transaction['public_id'], 'LOGIN', 'GOOGLE', $state, $binding, null) === null,
        'Consumed transaction must not recover PKCE verifier.'
    );
    $consumedSecretQuery = $pdo->prepare('SELECT pkce_verifier_secret_envelope FROM auth_transactions WHERE id = :id');
    $consumedSecretQuery->execute([':id' => $transaction['id']]);
    fc_test_assert($consumedSecretQuery->fetchColumn() === null, 'Consumption must remove the recoverable PKCE secret envelope.');

    $expired = fc_auth_transaction_create(
        $pdo,
        'LOGIN',
        'GOOGLE',
        null,
        'expired-state',
        'expired-binding',
        'ACCOUNT_ENTRY',
        null,
        'phase2a2-expired-pkce-verifier',
        300
    );
    $expire = $pdo->prepare(
        'UPDATE auth_transactions SET created_at = DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 10 MINUTE), expires_at = DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 MINUTE) WHERE id = :id'
    );
    $expire->execute([':id' => $expired['id']]);
    fc_test_assert(
        !fc_auth_transaction_consume($pdo, $expired['public_id'], 'LOGIN', 'GOOGLE', 'expired-state', 'expired-binding', null),
        'Expired transaction must not be consumed.'
    );
    fc_test_assert(
        fc_auth_transaction_recover_pkce_verifier($pdo, $expired['public_id'], 'LOGIN', 'GOOGLE', 'expired-state', 'expired-binding', null) === null,
        'Expired transaction must not recover PKCE verifier.'
    );
    $expiredSecretQuery = $pdo->prepare('SELECT pkce_verifier_secret_envelope FROM auth_transactions WHERE id = :id');
    $expiredSecretQuery->execute([':id' => $expired['id']]);
    fc_test_assert($expiredSecretQuery->fetchColumn() === null, 'Expired transaction must clear recoverable PKCE envelope during lifecycle cleanup.');

    fc_test_expect_failure(
        fn () => fc_auth_destination_path('https://evil.example/redirect'),
        'Arbitrary post-auth destination must be rejected.'
    );

    $secretToken = 'phase2a2-secret-access-token';
    $rawAuditSession = 'phase2a2-raw-audit-session';
    $audit = fc_audit_event_write($pdo, [
        'actor_user_id' => $user1['id'],
        'event_type' => 'PHASE2A2_FOUNDATION_PROOF',
        'target_type' => 'user',
        'target_id' => $user1['public_id'],
        'outcome' => 'SUCCESS',
        'request_id' => fc_new_public_id(),
        'metadata' => [
            'proof' => 'safe-value',
            'access_token' => $secretToken,
            'pkce_verifier' => $pkce,
            'pkce_verifier_secret_envelope' => (string) ($txRow['pkce_verifier_secret_envelope'] ?? ''),
            'nested' => ['raw_session_id' => $rawAuditSession, 'safe_nested' => true],
        ],
        'raw_client_evidence' => '127.0.0.1',
    ]);
    $auditQuery = $pdo->prepare('SELECT metadata_json FROM audit_events WHERE id = :id');
    $auditQuery->execute([':id' => $audit['id']]);
    $auditJson = (string) $auditQuery->fetchColumn();
    fc_test_assert(str_contains($auditJson, 'safe-value'), 'Safe audit metadata should be preserved.');
    fc_test_assert(!str_contains($auditJson, $secretToken), 'Provider access token must not be stored in audit metadata.');
    fc_test_assert(!str_contains($auditJson, $rawAuditSession), 'Raw session ID must not be stored in audit metadata.');
    fc_test_assert(!str_contains($auditJson, 'access_token'), 'Sensitive audit keys should be removed.');
    fc_test_assert(!str_contains($auditJson, 'raw_session_id'), 'Nested sensitive audit keys should be removed.');
    fc_test_assert(!str_contains($auditJson, $pkce), 'Raw PKCE verifier must not be stored in audit metadata.');
    fc_test_assert(!str_contains($auditJson, 'pkce_verifier_secret_envelope'), 'Protected PKCE envelope must not be copied into audit metadata.');

    $pdo->rollBack();

    echo "Phase 2A2 identity foundation proof: PASS\n";
    echo "- DB session timezone normalized to UTC: PASS\n";
    echo "- users / ULID / account constraints: PASS\n";
    echo "- provider identity uniqueness / email non-identity: PASS\n";
    echo "- provider email verification TRUE/FALSE/UNKNOWN: PASS\n";
    echo "- contact email canonicalization / shared-address / primary rules: PASS\n";
    echo "- hashed sessions / individual + all-session revocation: PASS\n";
    echo "- session user/auth-identity ownership integrity: PASS\n";
    echo "- auth transaction binding / expiry / single-use / destination allowlist: PASS\n";
    echo "- recoverable protected PKCE verifier lifecycle: PASS\n";
    echo "- audit secret redaction: PASS\n";
    echo "- held downstream / legacy product-table boundary: PASS\n";
    exit(0);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
