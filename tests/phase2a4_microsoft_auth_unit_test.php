<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

function fc_ms_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fc_ms_test_expect_domain(callable $callback, string $expectedMessage, string $label): void
{
    try {
        $callback();
    } catch (DomainException $e) {
        fc_ms_test_assert($e->getMessage() === $expectedMessage, $label . ': wrong rejection code (' . $e->getMessage() . ')');
        return;
    }

    throw new RuntimeException($label . ': expected DomainException');
}

try {
    $_ENV['APP_URL'] = 'https://fitcrewchallenge.com';
    $_ENV['APP_ENV'] = 'production';
    $_ENV['MICROSOFT_AUTH_CLIENT_ID'] = '11111111-2222-3333-4444-555555555555';
    $_ENV['MICROSOFT_AUTH_CLIENT_SECRET'] = 'unit-test-secret-not-a-real-credential';
    $_ENV['MICROSOFT_AUTH_REDIRECT_URI'] = 'https://fitcrewchallenge.com/auth/microsoft/callback.php';
    $_ENV['MICROSOFT_AUTH_CONSUMER_VISIBLE'] = 'false';
    $_ENV['PRELAUNCH_AUTH_PROOF_MODE'] = 'true';

    $tenantId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    $objectId = '11111111-aaaa-4bbb-8ccc-222222222222';
    $nonce = 'phase2a4-unit-nonce';
    $nonceHash = fc_secret_evidence_hash($nonce);
    $now = time();
    $_ENV['PRELAUNCH_MICROSOFT_ALLOWED_IDENTITIES'] = $tenantId . ':' . $objectId;

    $config = fc_microsoft_auth_config();
    fc_microsoft_validate_runtime_config($config);
    fc_ms_test_assert(fc_microsoft_auth_enabled() === false, 'Microsoft auth unexpectedly enabled without MICROSOFT_AUTH_ENABLED=true');
    $_ENV['MICROSOFT_AUTH_ENABLED'] = 'true';
    fc_ms_test_assert(fc_microsoft_auth_enabled(), 'valid Microsoft runtime configuration was not enabled');
    fc_ms_test_assert(!fc_microsoft_auth_consumer_visible(), 'Microsoft consumer visibility was not default-off');
    fc_ms_test_assert(!fc_microsoft_auth_consumer_available(), 'hidden Microsoft provider was consumer-available');
    $_ENV['MICROSOFT_AUTH_CONSUMER_VISIBLE'] = 'true';
    fc_ms_test_assert(fc_microsoft_auth_consumer_available(), 'visible enabled Microsoft provider was not consumer-available');

    $verifier = fc_microsoft_pkce_verifier();
    fc_ms_test_assert(strlen($verifier) >= 43 && strlen($verifier) <= 128, 'PKCE verifier length invalid');
    $challenge = fc_microsoft_pkce_challenge($verifier);
    fc_ms_test_assert($challenge === fc_base64url_encode(hash('sha256', $verifier, true)), 'PKCE S256 challenge mismatch');

    $stateEnvelope = fc_microsoft_state_encode('01K4Z8F3F5M9T2W7Y6X4R8Q1NP', 'phase2a4_state_token_abcdefghijklmnopqrstuvwxyz');
    $decodedState = fc_microsoft_state_decode($stateEnvelope);
    fc_ms_test_assert(is_array($decodedState) && $decodedState['transaction_id'] === '01K4Z8F3F5M9T2W7Y6X4R8Q1NP', 'Microsoft state envelope decode failed');
    fc_ms_test_assert(fc_microsoft_state_decode('bad-state') === null, 'invalid Microsoft state envelope accepted');

    $payload = [
        'ver' => '2.0',
        'aud' => $_ENV['MICROSOFT_AUTH_CLIENT_ID'],
        'exp' => $now + 600,
        'nbf' => $now - 60,
        'iss' => fc_microsoft_expected_issuer($tenantId),
        'tid' => $tenantId,
        'oid' => $objectId,
        'sub' => 'pairwise-microsoft-subject-unit',
        'nonce' => $nonce,
        'email' => 'same-email@example.com',
        'name' => 'Microsoft Proof User',
    ];
    $claims = fc_microsoft_validate_verified_payload(
        $payload,
        $_ENV['MICROSOFT_AUTH_CLIENT_ID'],
        $nonceHash,
        $now,
        FC_MICROSOFT_ISSUER_TEMPLATE
    );

    if (!class_exists(\Firebase\JWT\JWT::class) || !class_exists(\Firebase\JWT\JWK::class)) {
        throw new RuntimeException('Firebase PHP-JWT dependency is unavailable for Microsoft signature proof');
    }
    // Use a fixed, synthetic test-only RSA key rather than generating one at runtime.
    // Some Windows PHP/OpenSSL builds cannot generate RSA keys without an external
    // openssl.cnf even though normal RS256 signing/verification works correctly.
    // This fixture is not a FitCrew credential and has no use outside this test.
    $privatePem = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQCTvW7ZqSTUlOzP
a3jVIPtp3b/Ml+ewo6AUmJOTJ61Jva3C2Qtdd12IiNPxeIp0VWIBklOzJoR48E5f
t/vAwfTD6u5vYwhS6KV9hOaMLzrWW7MO2WefL04+fLEq19LqqmGRNQ6ow9JwytBj
kDVupqZzRIeUFWVQGvxuaqWnTekAMJKq01S3C/cwpL0On0wSxrOuWTWeKceKkXH8
sKlrFBTxqBopU7LjMA6oX9hOruQ6c472hB6EV1JehF3yDaV+WiPaqDNolyTofFcM
ZRKCWJ6XnHGYzOUPUe0j1ejtKBbTZSUpdilzGdyGzgkdOC3J/j2VeroLys5xERfZ
bK6UyVPdAgMBAAECggEAGJ5esCkKIN1vJ7I0OKmuE2pW+WgPvOTLOlthbgpUyz6v
C5LA3NKU9Lk+PhT012VZ90KTrXz74q5ClRsyuaBoYdROZqPFQZGQcB3bEB2Xq5ws
tor+RC2tF2cOW8IKnL2HFBwYBQHamZ6GQq0HZlihUIBpKkE1eHeCvICLeZlXPAdb
Fs9BSuwIkJcc8VJSCcVvdw+xvsrjBX6ajM2OzaG9VfdNG3KUqa00qul3It7EeZGt
QMIWJ6cMzpzfdNz7B9lSTM+Ip70pblaKm3+4k1oPyhCK2RKooLYuOYMFvAyzVsWd
/OpluCjNSMlL6OLkcxJ716rCEwgipAxBhHfBfsoU2QKBgQDQGibyelUr3CASrwuj
8xetz8sLZnEuH4iZJK1FCgganNHzijxqK2AXbKdaGrU0CoReESsJQdOBvnVyT4lX
D4xXlO2QhI5QAF++HEGYIcLI0/+6uYBMJj7oJhGj0rkhVHDaeDesO+8A/dgl/1Ms
jTRnts8X2bMzxizlfHZa6m0KqQKBgQC1vprh1mI9nGjT7NXscTPX6wLEnbxjtFVj
g812UbcINbnuahLGWmmPEVXKeuNznjG78/w0G/dajscQfcGIUe1jTDhsvvdDmRW2
dLj2pTsYV1hcU4pHejGwQE5G0xQklbwUG5h0cm7kbdSu8vRDaHkywnBDFJSFPlRw
g2lqDphUFQKBgFO/EolmXbxG28lpFGAoRhE2nFm8IjQTkJ9AuVIdVWGQVpWRvfpj
9km2+ioysVV+HgLVGeKh0QJXqWAVTgnxQeNFFc2g6rybSexx5pvYilDTsAhu+oiq
o4o9N8Ab31DgcIIa+xyfCfH2NfQkqk41jS9nzDOm8o0VZZ/81IyF5tfxAoGAUtys
UPsln2GIidcZUcvrDy6e/HXORscZh124d5GaGawlMYY7OSRPrGMC8mZE/ZnKox8C
hd+aTzd5mxM7AaQYz4UY1LvckH1jYOSm0A1VfCuWpcGQ8jXMIcev6KqkLGn4blKG
l9D0rkFFDt7Gb1VarMHp3Sus63MXnJTNowU0fmECgYBkMJJT/tZAenUKj34hwEKO
YUdHwhBBXz+L3hxtYF8qV7hiFF1OCKcvfRUE8W+WDft0PPZMRTQTigCmD88AmbCf
YD02gBfbIR0uPzPkqf6iVp3U4Vs2uRL2IVbHddpl9JwGy4VFU6myzsoSsTJq9GDB
7ICuRmpdQVPY4PxxbRutdQ==
-----END PRIVATE KEY-----
PEM;
    $privateKey = openssl_pkey_get_private($privatePem);
    if ($privateKey === false) {
        throw new RuntimeException('Unable to load synthetic RSA fixture for Microsoft signature proof');
    }
    $details = openssl_pkey_get_details($privateKey);
    if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) {
        throw new RuntimeException('Unable to inspect synthetic RSA key for Microsoft signature proof');
    }
    $jwk = [
        'kty' => 'RSA',
        'use' => 'sig',
        'kid' => 'phase2a4-unit-key',
        'alg' => 'RS256',
        'n' => fc_base64url_encode($details['rsa']['n']),
        'e' => fc_base64url_encode($details['rsa']['e']),
        'issuer' => FC_MICROSOFT_ISSUER_TEMPLATE,
    ];
    $signedToken = \Firebase\JWT\JWT::encode($payload, $privatePem, 'RS256', 'phase2a4-unit-key');
    $signatureVerified = fc_microsoft_verify_id_token_with(
        $signedToken,
        $_ENV['MICROSOFT_AUTH_CLIENT_ID'],
        $nonceHash,
        static fn (): array => ['keys' => [$jwk]],
        $now
    );
    fc_ms_test_assert($signatureVerified['provider_object_id'] === $objectId, 'signed Microsoft ID-token verification failed');

    $segments = explode('.', $signedToken);
    $signatureBytes = fc_base64url_decode($segments[2]);
    if ($signatureBytes === '') {
        throw new RuntimeException('Synthetic Microsoft JWT signature was unexpectedly empty');
    }
    // Flip a real signature byte rather than only changing a base64url character.
    // The final base64url character can contain unused padding bits, so changing it
    // is not guaranteed to change the decoded signature bytes on every decoder.
    $signatureBytes[0] = chr(ord($signatureBytes[0]) ^ 0x01);
    $segments[2] = fc_base64url_encode($signatureBytes);
    $tamperedToken = implode('.', $segments);
    fc_ms_test_expect_domain(
        fn () => fc_microsoft_verify_id_token_with(
            $tamperedToken,
            $_ENV['MICROSOFT_AUTH_CLIENT_ID'],
            $nonceHash,
            static fn (): array => ['keys' => [$jwk]],
            $now
        ),
        'microsoft_id_token_invalid',
        'invalid Microsoft ID-token signature'
    );
    fc_ms_test_assert($claims['provider_tenant_id'] === $tenantId, 'Microsoft tenant normalization failed');
    fc_ms_test_assert($claims['provider_object_id'] === $objectId, 'Microsoft object normalization failed');
    fc_ms_test_assert($claims['protocol_subject'] === 'pairwise-microsoft-subject-unit', 'Microsoft protocol subject normalization failed');
    fc_ms_test_assert($claims['provider_email_verified'] === null, 'Microsoft email verification was manufactured as TRUE/FALSE');
    fc_ms_test_assert(fc_microsoft_prelaunch_allows_new_account($claims), 'allowed tenant/object proof identity was denied');

    $personalPayload = $payload;
    $personalPayload['tid'] = FC_MICROSOFT_CONSUMER_TENANT_ID;
    $personalPayload['oid'] = '33333333-4444-4555-8666-777777777777';
    $personalPayload['iss'] = fc_microsoft_expected_issuer(FC_MICROSOFT_CONSUMER_TENANT_ID);
    fc_ms_test_assert(
        fc_microsoft_validate_verified_payload(
            $personalPayload,
            $_ENV['MICROSOFT_AUTH_CLIENT_ID'],
            $nonceHash,
            $now,
            FC_MICROSOFT_CONSUMER_TENANT_ID === $personalPayload['tid'] ? $personalPayload['iss'] : FC_MICROSOFT_ISSUER_TEMPLATE
        )['provider_tenant_id'] === FC_MICROSOFT_CONSUMER_TENANT_ID,
        'personal Microsoft account tenant was not accepted'
    );

    $wrongAudience = $payload;
    $wrongAudience['aud'] = '99999999-2222-3333-4444-555555555555';
    fc_ms_test_expect_domain(
        fn () => fc_microsoft_validate_verified_payload($wrongAudience, $_ENV['MICROSOFT_AUTH_CLIENT_ID'], $nonceHash, $now, FC_MICROSOFT_ISSUER_TEMPLATE),
        'microsoft_audience_invalid',
        'wrong audience'
    );

    $expired = $payload;
    $expired['exp'] = $now - 1;
    fc_ms_test_expect_domain(
        fn () => fc_microsoft_validate_verified_payload($expired, $_ENV['MICROSOFT_AUTH_CLIENT_ID'], $nonceHash, $now, FC_MICROSOFT_ISSUER_TEMPLATE),
        'microsoft_id_token_expired',
        'expired ID token'
    );

    $wrongNonce = $payload;
    $wrongNonce['nonce'] = 'wrong-nonce';
    fc_ms_test_expect_domain(
        fn () => fc_microsoft_validate_verified_payload($wrongNonce, $_ENV['MICROSOFT_AUTH_CLIENT_ID'], $nonceHash, $now, FC_MICROSOFT_ISSUER_TEMPLATE),
        'microsoft_nonce_invalid',
        'wrong nonce'
    );

    $issuerMismatch = $payload;
    $issuerMismatch['iss'] = fc_microsoft_expected_issuer('bbbbbbbb-cccc-4ddd-8eee-ffffffffffff');
    fc_ms_test_expect_domain(
        fn () => fc_microsoft_validate_verified_payload($issuerMismatch, $_ENV['MICROSOFT_AUTH_CLIENT_ID'], $nonceHash, $now, FC_MICROSOFT_ISSUER_TEMPLATE),
        'microsoft_issuer_invalid',
        'issuer/tid mismatch'
    );

    $missingTid = $payload;
    unset($missingTid['tid']);
    fc_ms_test_expect_domain(
        fn () => fc_microsoft_validate_verified_payload($missingTid, $_ENV['MICROSOFT_AUTH_CLIENT_ID'], $nonceHash, $now, FC_MICROSOFT_ISSUER_TEMPLATE),
        'microsoft_tid_invalid',
        'missing tid'
    );

    $invalidOid = $payload;
    $invalidOid['oid'] = 'not-a-guid';
    fc_ms_test_expect_domain(
        fn () => fc_microsoft_validate_verified_payload($invalidOid, $_ENV['MICROSOFT_AUTH_CLIENT_ID'], $nonceHash, $now, FC_MICROSOFT_ISSUER_TEMPLATE),
        'microsoft_oid_invalid',
        'invalid oid'
    );

    fc_ms_test_expect_domain(
        fn () => fc_microsoft_validate_verified_payload($payload, $_ENV['MICROSOFT_AUTH_CLIENT_ID'], $nonceHash, $now, fc_microsoft_expected_issuer('bbbbbbbb-cccc-4ddd-8eee-ffffffffffff')),
        'microsoft_signing_key_issuer_invalid',
        'wrong signing-key issuer'
    );

    $deniedClaims = $claims;
    $deniedClaims['provider_object_id'] = '88888888-9999-4aaa-8bbb-cccccccccccc';
    $deniedClaims['email_at_provider'] = 'same-email@example.com';
    fc_ms_test_assert(!fc_microsoft_prelaunch_allows_new_account($deniedClaims), 'matching email bypassed Microsoft tid+oid prelaunch gate');

    $exchangeFailureCases = [
        'invalid_client' => 'microsoft_code_exchange_invalid_client',
        'invalid_grant' => 'microsoft_code_exchange_invalid_grant',
        'invalid_scope' => 'microsoft_code_exchange_invalid_scope',
        'unauthorized_client' => 'microsoft_code_exchange_unauthorized_client',
        'server_error' => 'microsoft_code_exchange_provider_unavailable',
        'temporarily_unavailable' => 'microsoft_code_exchange_provider_unavailable',
        'unrecognized_provider_error' => 'microsoft_code_exchange_failed',
    ];
    foreach ($exchangeFailureCases as $providerError => $expectedExceptionCode) {
        fc_ms_test_assert(
            fc_microsoft_code_exchange_exception_code([
                'error' => $providerError,
                'error_description' => 'sensitive provider detail must be ignored',
                'trace_id' => 'provider-trace-must-be-ignored',
                'correlation_id' => 'provider-correlation-must-be-ignored',
            ]) === $expectedExceptionCode,
            'Microsoft code-exchange error was not safely classified: ' . $providerError
        );
    }
    fc_ms_test_assert(
        fc_microsoft_code_exchange_exception_code(['error' => ['invalid_client']]) === 'microsoft_code_exchange_failed',
        'non-string Microsoft code-exchange error was not reduced to the generic classification'
    );

    $transportCalls = 0;
    $expectedVerifier = $verifier;
    $usedCodes = [];
    $transport = static function (string $url, array $fields) use (&$transportCalls, &$usedCodes, $expectedVerifier): array {
        $transportCalls++;
        if ($url !== FC_MICROSOFT_TOKEN_ENDPOINT) {
            throw new DomainException('microsoft_code_exchange_failed');
        }
        if (($fields['scope'] ?? '') !== 'openid profile email' || str_contains((string) ($fields['scope'] ?? ''), 'offline_access')) {
            throw new DomainException('microsoft_code_exchange_failed');
        }
        if (($fields['code_verifier'] ?? '') !== $expectedVerifier) {
            throw new DomainException('microsoft_code_exchange_failed');
        }
        $code = (string) ($fields['code'] ?? '');
        if ($code === '' || $code === 'invalid-code' || isset($usedCodes[$code])) {
            throw new DomainException('microsoft_code_exchange_failed');
        }
        $usedCodes[$code] = true;
        return [
            'id_token' => 'synthetic-id-token',
            'access_token' => 'transient-access-token-that-must-be-discarded',
        ];
    };

    fc_ms_test_assert(
        fc_microsoft_exchange_authorization_code_with('one-time-code', $verifier, $config, $transport) === 'synthetic-id-token',
        'valid authorization-code exchange did not return the ID token'
    );
    fc_ms_test_expect_domain(
        fn () => fc_microsoft_exchange_authorization_code_with('one-time-code', $verifier, $config, $transport),
        'microsoft_code_exchange_failed',
        'authorization-code reuse'
    );
    fc_ms_test_expect_domain(
        fn () => fc_microsoft_exchange_authorization_code_with('invalid-code', $verifier, $config, $transport),
        'microsoft_code_exchange_failed',
        'invalid authorization code'
    );
    $wrongVerifier = fc_microsoft_pkce_verifier();
    fc_ms_test_expect_domain(
        fn () => fc_microsoft_exchange_authorization_code_with('fresh-code', $wrongVerifier, $config, $transport),
        'microsoft_code_exchange_failed',
        'wrong PKCE verifier'
    );

    fc_ms_test_assert(FC_MICROSOFT_SCOPES === 'openid profile email', 'Microsoft scopes are not the exact authentication-only scope set');
    fc_ms_test_assert(!str_contains(FC_MICROSOFT_SCOPES, 'offline_access'), 'Microsoft offline_access was requested');
    fc_ms_test_assert(fc_microsoft_request_origin_valid('https://fitcrewchallenge.com'), 'Microsoft same-origin request was rejected');
    fc_ms_test_assert(!fc_microsoft_request_origin_valid(null), 'Microsoft missing-origin request was accepted');
    fc_ms_test_assert(!fc_microsoft_request_origin_valid('null'), 'Microsoft opaque-origin request was accepted');
    fc_ms_test_assert(!fc_microsoft_request_origin_valid('https://evil.example'), 'Microsoft wrong-origin request was accepted');

    $loginController = file_get_contents(fc_path('login.php')) ?: '';
    $loginView = file_get_contents(fc_path('views/auth/login.php')) ?: '';
    fc_ms_test_assert(
        strpos($loginView, 'Continue with Google') < strpos($loginView, 'Continue with Apple'),
        'visible consumer provider order is not Google → Apple'
    );
    fc_ms_test_assert(strpos($loginController, 'fc_microsoft_auth_consumer_available()') !== false, 'login controller bypasses Microsoft consumer-visibility control');
    fc_ms_test_assert(strpos($loginView, 'if ($microsoftVisible)') !== false, 'Microsoft login choice is not guarded by consumer visibility');

    $entryIntent = 'signin';
    $googleAuthConfig = ['enabled' => false, 'reason' => 'Unit-test placeholder'];
    $microsoftAuthConfig = ['visible' => false, 'enabled' => true];
    ob_start();
    require fc_path('views/auth/login.php');
    $hiddenLoginHtml = (string) ob_get_clean();
    fc_ms_test_assert(!str_contains($hiddenLoginHtml, 'Continue with Microsoft'), 'hidden Microsoft provider was rendered to consumers');

    $microsoftAuthConfig = ['visible' => true, 'enabled' => false, 'reason' => 'Unit-test placeholder'];
    ob_start();
    require fc_path('views/auth/login.php');
    $visibleLoginHtml = (string) ob_get_clean();
    fc_ms_test_assert(str_contains($visibleLoginHtml, 'Continue with Microsoft'), 'visible Microsoft provider was not rendered');

    $microsoftSource = '';
    foreach ([
        'inc/auth/microsoft.php',
        'auth/microsoft/start.php',
        'auth/microsoft/callback.php',
        'auth/microsoft/complete.php',
    ] as $path) {
        $microsoftSource .= file_get_contents(fc_path($path)) ?: '';
    }
    fc_ms_test_assert(strpos($microsoftSource, "'response_type' => 'code'") !== false, 'Microsoft auth code flow is absent');
    fc_ms_test_assert(strpos($microsoftSource, 'fc_microsoft_auth_consumer_available()') !== false, 'direct Microsoft initiation is not blocked while consumer visibility is deferred');
    fc_ms_test_assert(strpos($microsoftSource, "'response_mode' => 'form_post'") !== false, 'Microsoft form_post code-return protection is absent');
    fc_ms_test_assert(strpos($microsoftSource, "'code_challenge_method' => 'S256'") !== false, 'Microsoft PKCE S256 is absent');
    fc_ms_test_assert(stripos($microsoftSource, 'google health') === false, 'Microsoft auth source crossed Google Health boundary');
    fc_ms_test_assert(stripos($microsoftSource, 'graph.microsoft.com') === false, 'Microsoft Graph product integration was introduced');
    fc_ms_test_assert(strpos($microsoftSource, "['error_description']") === false, 'Microsoft raw token error description is inspected or retained');
    fc_ms_test_assert(strpos($microsoftSource, "['trace_id']") === false, 'Microsoft raw token trace identifier is inspected or retained');
    fc_ms_test_assert(strpos($microsoftSource, "['correlation_id']") === false, 'Microsoft raw token correlation identifier is inspected or retained');

    $callbackSource = file_get_contents(fc_path('auth/microsoft/callback.php')) ?: '';
    fc_ms_test_assert(strpos($callbackSource, 'inc/bootstrap.php') === false, 'Microsoft form_post bridge starts a FitCrew session and can overwrite SameSite=Lax browser binding');
    fc_ms_test_assert(strpos($callbackSource, "header('Referrer-Policy: same-origin');") !== false, 'Microsoft form_post bridge does not preserve a concrete same-origin completion Origin');
    fc_ms_test_assert(strpos($callbackSource, "header('Referrer-Policy: no-referrer');") === false, 'Microsoft form_post bridge still serializes its completion Origin as null');

    $migrationSource = '';
    foreach (glob(fc_path('database/migrations/*.sql')) ?: [] as $migrationFile) {
        $migrationSource .= file_get_contents($migrationFile) ?: '';
    }
    foreach (['access_token', 'refresh_token', 'id_token', 'authorization_code', 'client_secret'] as $forbiddenColumn) {
        fc_ms_test_assert(stripos($migrationSource, $forbiddenColumn) === false, 'Microsoft secret/token persistence column was introduced: ' . $forbiddenColumn);
    }

    $redactionSource = file_get_contents(fc_path('inc/security/redaction.php')) ?: '';
    foreach (['microsoft_authorization_code', 'microsoft_auth_client_secret', 'microsoft_id_token', 'microsoft_access_token', 'microsoft_refresh_token'] as $key) {
        fc_ms_test_assert(strpos($redactionSource, "'{$key}'") !== false, 'Microsoft sensitive-key redaction coverage missing: ' . $key);
    }

    $_SESSION['fitcrew_csrf_token'] = 'phase2a4-csrf-good';
    fc_ms_test_assert(!fc_validate_csrf('phase2a4-csrf-bad'), 'Microsoft start CSRF failure was accepted');

    echo "Phase 2A4 Microsoft authentication unit proof: PASS\n";
    echo "- Authorization Code + OIDC + PKCE S256 contract: PASS\n";
    echo "- invalid / reused authorization-code rejection: PASS\n";
    echo "- wrong PKCE verifier rejection: PASS\n";
    echo "- trusted RS256 signature / invalid-signature rejection: PASS\n";
    echo "- wrong audience / expired token / nonce rejection: PASS\n";
    echo "- issuer + tid + signing-key issuer validation: PASS\n";
    echo "- missing tid / invalid oid rejection: PASS\n";
    echo "- personal + work/school tenant model: PASS\n";
    echo "- provider email verification remains UNKNOWN: PASS\n";
    echo "- tid+oid prelaunch gate / email cannot bypass gate: PASS\n";
    echo "- no offline_access / Microsoft Graph scope: PASS\n";
    echo "- form_post bridge preserves concrete Origin + SameSite=Lax browser binding: PASS\n";
    echo "- Google → Apple visible consumer order / Microsoft visibility gate: PASS\n";
    echo "- no Microsoft token/code/client-secret persistence columns: PASS\n";
    echo "- Microsoft secret redaction coverage: PASS\n";
    echo "- safe token-endpoint failure classification / raw detail discard: PASS\n";
    echo "- same-origin + CSRF start controls: PASS\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
