<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

function fc_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fc_test_expect_domain(callable $callback, string $expectedMessage, string $label): void
{
    try {
        $callback();
    } catch (DomainException $e) {
        fc_test_assert($e->getMessage() === $expectedMessage, $label . ': wrong rejection code');
        return;
    }

    throw new RuntimeException($label . ': expected DomainException');
}

try {
    $_ENV['GOOGLE_AUTH_CLIENT_ID'] = 'fitcrew-test-client.apps.googleusercontent.com';
    $_ENV['PRELAUNCH_AUTH_PROOF_MODE'] = 'true';
    $_ENV['PRELAUNCH_AUTH_ALLOWED_EMAILS'] = 'proof@example.com';
    $_ENV['APP_URL'] = 'https://fitcrewchallenge.com';

    $nonce = 'unit-proof-nonce';
    $nonceHash = fc_secret_evidence_hash($nonce);
    $now = time();
    $payload = [
        'iss' => 'https://accounts.google.com',
        'aud' => $_ENV['GOOGLE_AUTH_CLIENT_ID'],
        'exp' => $now + 600,
        'sub' => 'google-subject-unit-1',
        'nonce' => $nonce,
        'email' => 'proof@example.com',
        'email_verified' => true,
        'name' => 'Proof User',
    ];

    $verified = fc_google_verify_id_token_with(
        'synthetic-id-token',
        $_ENV['GOOGLE_AUTH_CLIENT_ID'],
        $nonceHash,
        static fn (string $token): array|false => $token === 'synthetic-id-token' ? $payload : false,
        $now
    );
    fc_test_assert($verified['provider_subject'] === 'google-subject-unit-1', 'valid Google payload normalization failed');

    fc_test_expect_domain(
        fn () => fc_google_verify_id_token_with('bad', $_ENV['GOOGLE_AUTH_CLIENT_ID'], $nonceHash, static fn (): false => false, $now),
        'google_credential_invalid',
        'invalid token'
    );

    $wrongAudience = $payload;
    $wrongAudience['aud'] = 'wrong-client.apps.googleusercontent.com';
    fc_test_expect_domain(
        fn () => fc_google_validate_verified_payload($wrongAudience, $_ENV['GOOGLE_AUTH_CLIENT_ID'], $nonceHash, $now),
        'google_audience_invalid',
        'wrong audience'
    );

    $expired = $payload;
    $expired['exp'] = $now - 1;
    fc_test_expect_domain(
        fn () => fc_google_validate_verified_payload($expired, $_ENV['GOOGLE_AUTH_CLIENT_ID'], $nonceHash, $now),
        'google_credential_expired',
        'expired token'
    );

    $wrongNonce = $payload;
    $wrongNonce['nonce'] = 'wrong-nonce';
    fc_test_expect_domain(
        fn () => fc_google_validate_verified_payload($wrongNonce, $_ENV['GOOGLE_AUTH_CLIENT_ID'], $nonceHash, $now),
        'google_nonce_invalid',
        'wrong nonce'
    );

    fc_test_assert(fc_google_prelaunch_allows_new_account($verified), 'allowed prelaunch proof account was denied');
    $deniedClaims = $verified;
    $deniedClaims['email_at_provider'] = 'other@example.com';
    fc_test_assert(!fc_google_prelaunch_allows_new_account($deniedClaims), 'non-allowed prelaunch account was accepted');
    $unverifiedClaims = $verified;
    $unverifiedClaims['provider_email_verified'] = 0;
    fc_test_assert(!fc_google_prelaunch_allows_new_account($unverifiedClaims), 'unverified prelaunch account was accepted');

    fc_test_assert(fc_google_request_origin_valid('https://fitcrewchallenge.com'), 'expected same origin was rejected');
    fc_test_assert(!fc_google_request_origin_valid('https://evil.example'), 'wrong origin was accepted');

    $loginView = file_get_contents(fc_path('views/auth/login.php')) ?: '';
    fc_test_assert(
        strpos($loginView, 'Continue with Google') < strpos($loginView, 'Continue with Apple')
        && strpos($loginView, 'Continue with Apple') < strpos($loginView, 'Continue with Microsoft'),
        'account-entry provider order is not Google → Apple → Microsoft'
    );

    $providerSource = (file_get_contents(fc_path('inc/auth/google.php')) ?: '')
        . (file_get_contents(fc_path('assets/js/google-auth.js')) ?: '')
        . (file_get_contents(fc_path('auth/google/credential.php')) ?: '');
    foreach (['GOOGLE_HEALTH_CLIENT_ID', 'GOOGLE_HEALTH_CLIENT_SECRET', 'health access token', 'health refresh token'] as $forbidden) {
        fc_test_assert(stripos($providerSource, $forbidden) === false, 'Google auth source crossed health authorization boundary');
    }

    $googleJs = file_get_contents(fc_path('assets/js/google-auth.js')) ?: '';
    fc_test_assert(strpos($googleJs, 'use_fedcm_for_button: true') !== false, 'Google button is not configured for FedCM');
    fc_test_assert(strpos($googleJs, "text: 'continue_with'") !== false, 'official Google button is not configured for Continue with Google');
    fc_test_assert(strpos($googleJs, 'google.accounts.id.prompt') === false, 'Google One Tap/prompt was enabled');
    fc_test_assert(strpos($googleJs, 'google.accounts.oauth2') === false, 'Google authorization/access-token API was introduced');

    $migrationSource = '';
    foreach (glob(fc_path('database/migrations/*.sql')) ?: [] as $migrationFile) {
        $migrationSource .= file_get_contents($migrationFile) ?: '';
    }
    foreach (['access_token', 'refresh_token', 'id_token'] as $forbiddenColumn) {
        fc_test_assert(stripos($migrationSource, $forbiddenColumn) === false, 'Google token persistence column was introduced');
    }

    $originalEnv = $_ENV['APP_ENV'] ?? null;
    $originalEnvProcess = getenv('APP_ENV');
    $originalIdle = $_ENV['SESSION_IDLE_SECONDS'] ?? null;
    $originalIdleProcess = getenv('SESSION_IDLE_SECONDS');
    $originalAbsolute = $_ENV['SESSION_ABSOLUTE_SECONDS'] ?? null;
    $originalAbsoluteProcess = getenv('SESSION_ABSOLUTE_SECONDS');

    $_ENV['APP_ENV'] = 'production';
    putenv('APP_ENV=production');
    unset($_ENV['SESSION_IDLE_SECONDS'], $_ENV['SESSION_ABSOLUTE_SECONDS']);
    putenv('SESSION_IDLE_SECONDS');
    putenv('SESSION_ABSOLUTE_SECONDS');

    try {
        try {
            fc_session_timeout_policy();
            throw new RuntimeException('production session policy accepted missing explicit timeouts');
        } catch (RuntimeException $e) {
            fc_test_assert(str_contains($e->getMessage(), 'must be explicitly configured'), 'unexpected production session policy failure');
        }
    } finally {
        if ($originalEnv === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $originalEnv;
        if ($originalIdle === null) unset($_ENV['SESSION_IDLE_SECONDS']); else $_ENV['SESSION_IDLE_SECONDS'] = $originalIdle;
        if ($originalAbsolute === null) unset($_ENV['SESSION_ABSOLUTE_SECONDS']); else $_ENV['SESSION_ABSOLUTE_SECONDS'] = $originalAbsolute;

        if ($originalEnvProcess === false) putenv('APP_ENV'); else putenv('APP_ENV=' . $originalEnvProcess);
        if ($originalIdleProcess === false) putenv('SESSION_IDLE_SECONDS'); else putenv('SESSION_IDLE_SECONDS=' . $originalIdleProcess);
        if ($originalAbsoluteProcess === false) putenv('SESSION_ABSOLUTE_SECONDS'); else putenv('SESSION_ABSOLUTE_SECONDS=' . $originalAbsoluteProcess);
    }

    echo "Phase 2A3 Google authentication unit proof: PASS\n";
    echo "- invalid Google credential rejection: PASS\n";
    echo "- wrong audience rejection: PASS\n";
    echo "- expired credential rejection: PASS\n";
    echo "- nonce mismatch rejection: PASS\n";
    echo "- prelaunch new-account gate: PASS\n";
    echo "- same-origin enforcement: PASS\n";
    echo "- account-entry provider order: PASS\n";
    echo "- Google authentication / Google Health separation: PASS\n";
    echo "- explicit FedCM Google button / no One Tap or authorization API: PASS\n";
    echo "- Google ID/access/refresh token persistence absent from schema: PASS\n";
    echo "- production session timeout explicit-config contract: PASS\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
