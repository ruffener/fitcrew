<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';

function fc_recon_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fc_recon_expect_pdo_failure(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException $error) {
        return;
    }

    throw new RuntimeException($message);
}

$testSecretKey = base64_encode(str_repeat("\x37", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$_ENV['AUTH_TRANSACTION_SECRET_KEY_B64'] = $testSecretKey;
putenv('AUTH_TRANSACTION_SECRET_KEY_B64=' . $testSecretKey);

$pdo = fc_db();
$pdo->beginTransaction();

try {
    $userA = fc_user_create($pdo, 'Phase 2A2 Reconciliation A');
    $userB = fc_user_create($pdo, 'Phase 2A2 Reconciliation B');

    $identityTrue = fc_auth_identity_create($pdo, $userA['id'], [
        'provider_key' => 'GOOGLE',
        'issuer' => 'https://accounts.google.com',
        'provider_subject' => 'phase2a2-recon-true',
        'email_at_provider' => 'provider@example.test',
        'provider_email_verified' => true,
    ]);
    $identityFalse = fc_auth_identity_create($pdo, $userA['id'], [
        'provider_key' => 'APPLE',
        'issuer' => 'https://appleid.apple.com',
        'provider_subject' => 'phase2a2-recon-false',
        'email_at_provider' => 'provider@example.test',
        'provider_email_verified' => false,
    ]);
    $identityUnknown = fc_auth_identity_create($pdo, $userB['id'], [
        'provider_key' => 'GOOGLE',
        'issuer' => 'https://accounts.google.com',
        'provider_subject' => 'phase2a2-recon-unknown',
        'email_at_provider' => 'provider@example.test',
    ]);

    $claim = $pdo->prepare('SELECT provider_email_verified FROM user_auth_identities WHERE id = :id');
    $claim->execute([':id' => $identityTrue['id']]);
    fc_recon_assert((int) $claim->fetchColumn() === 1, 'TRUE provider verification claim was not preserved.');
    $claim->execute([':id' => $identityFalse['id']]);
    fc_recon_assert((int) $claim->fetchColumn() === 0, 'FALSE provider verification claim was not preserved.');
    $claim->execute([':id' => $identityUnknown['id']]);
    fc_recon_assert($claim->fetchColumn() === null, 'UNKNOWN provider verification claim was not preserved.');

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    fc_session_record_create(
        $pdo,
        $userA['id'],
        $identityTrue['id'],
        'phase2a2-recon-valid-session-' . bin2hex(random_bytes(16)),
        $now->modify('+20 minutes'),
        $now->modify('+8 hours')
    );

    fc_recon_expect_pdo_failure(function () use ($pdo, $userA, $identityUnknown, $now): void {
        $statement = $pdo->prepare(
            'INSERT INTO user_sessions (user_id, auth_identity_id, session_id_hash, idle_expires_at, absolute_expires_at) ' .
            'VALUES (:user_id, :identity_id, :session_hash, :idle_expires_at, :absolute_expires_at)'
        );
        $statement->execute([
            ':user_id' => $userA['id'],
            ':identity_id' => $identityUnknown['id'],
            ':session_hash' => fc_session_id_hash('phase2a2-recon-mismatch-' . bin2hex(random_bytes(16))),
            ':idle_expires_at' => $now->modify('+20 minutes')->format('Y-m-d H:i:s.u'),
            ':absolute_expires_at' => $now->modify('+8 hours')->format('Y-m-d H:i:s.u'),
        ]);
    }, 'Database accepted a cross-user session/auth-identity mismatch.');

    $state = 'phase2a2-recon-state-' . bin2hex(random_bytes(16));
    $binding = 'phase2a2-recon-binding-' . bin2hex(random_bytes(16));
    $verifier = 'phase2a2-recon-verifier-' . bin2hex(random_bytes(32));
    $transaction = fc_auth_transaction_create(
        $pdo,
        'LOGIN',
        'GOOGLE',
        null,
        $state,
        $binding,
        'APP_HOME',
        null,
        $verifier,
        300
    );

    $stored = $pdo->prepare('SELECT pkce_verifier_hash, pkce_verifier_secret_envelope FROM auth_transactions WHERE id = :id');
    $stored->execute([':id' => $transaction['id']]);
    $storedRow = $stored->fetch();
    fc_recon_assert($storedRow !== false, 'PKCE transaction was not stored.');
    fc_recon_assert((string) $storedRow['pkce_verifier_hash'] === fc_secret_evidence_hash($verifier), 'PKCE evidence hash was not retained.');
    fc_recon_assert(!empty($storedRow['pkce_verifier_secret_envelope']), 'Protected recoverable PKCE representation is missing.');
    fc_recon_assert(!str_contains((string) $storedRow['pkce_verifier_secret_envelope'], $verifier), 'Raw PKCE verifier leaked into database representation.');

    fc_recon_assert(
        fc_auth_transaction_recover_pkce_verifier($pdo, $transaction['public_id'], 'LOGIN', 'GOOGLE', $state, $binding, null) === $verifier,
        'Exact PKCE verifier was not recoverable for the valid bound transaction.'
    );
    fc_recon_assert(
        fc_auth_transaction_recover_pkce_verifier($pdo, $transaction['public_id'], 'LOGIN', 'GOOGLE', $state, 'wrong-binding', null) === null,
        'Wrong binding recovered PKCE verifier.'
    );
    fc_recon_assert(
        fc_auth_transaction_recover_pkce_verifier($pdo, fc_new_public_id(), 'LOGIN', 'GOOGLE', $state, $binding, null) === null,
        'Wrong transaction recovered PKCE verifier.'
    );

    fc_recon_assert(
        fc_auth_transaction_consume($pdo, $transaction['public_id'], 'LOGIN', 'GOOGLE', $state, $binding, null),
        'Valid transaction could not be consumed.'
    );
    fc_recon_assert(
        fc_auth_transaction_recover_pkce_verifier($pdo, $transaction['public_id'], 'LOGIN', 'GOOGLE', $state, $binding, null) === null,
        'Consumed transaction still exposed PKCE verifier.'
    );

    $expiredVerifier = 'phase2a2-recon-expired-' . bin2hex(random_bytes(24));
    $expired = fc_auth_transaction_create(
        $pdo,
        'LOGIN',
        'GOOGLE',
        null,
        'phase2a2-recon-expired-state',
        'phase2a2-recon-expired-binding',
        'ACCOUNT_ENTRY',
        null,
        $expiredVerifier,
        300
    );
    $expire = $pdo->prepare(
        'UPDATE auth_transactions SET created_at = DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 10 MINUTE), expires_at = DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 MINUTE) WHERE id = :id'
    );
    $expire->execute([':id' => $expired['id']]);
    fc_recon_assert(
        fc_auth_transaction_recover_pkce_verifier(
            $pdo,
            $expired['public_id'],
            'LOGIN',
            'GOOGLE',
            'phase2a2-recon-expired-state',
            'phase2a2-recon-expired-binding',
            null
        ) === null,
        'Expired transaction still exposed PKCE verifier.'
    );
    $expiredSecret = $pdo->prepare('SELECT pkce_verifier_secret_envelope FROM auth_transactions WHERE id = :id');
    $expiredSecret->execute([':id' => $expired['id']]);
    fc_recon_assert($expiredSecret->fetchColumn() === null, 'Expired transaction retained recoverable PKCE envelope after lifecycle cleanup.');

    $accountEntry = (string) file_get_contents(dirname(__DIR__) . '/views/auth/login.php');
    $google = strpos($accountEntry, 'Continue with Google');
    $apple = strpos($accountEntry, 'Continue with Apple');
    $microsoft = strpos($accountEntry, 'Continue with Microsoft');
    fc_recon_assert(
        $google !== false && $apple !== false && $microsoft !== false && $google < $apple && $apple < $microsoft,
        'Account-entry provider order is not Google → Apple → Microsoft.'
    );

    $pdo->rollBack();

    echo "Phase 2A2 targeted reconciliation proof: PASS\n";
    echo "- provider email verification TRUE/FALSE/UNKNOWN: PASS\n";
    echo "- session user/auth-identity database integrity: PASS\n";
    echo "- recoverable protected PKCE verifier lifecycle: PASS\n";
    echo "- account-entry order Google → Apple → Microsoft: PASS\n";
    exit(0);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
