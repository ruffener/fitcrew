<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__) . '/inc/security/redaction.php';
require_once dirname(__DIR__) . '/inc/security/protected_secrets.php';
require_once dirname(__DIR__) . '/inc/identity/contracts.php';
require_once dirname(__DIR__) . '/inc/identity/contact_emails.php';
require_once dirname(__DIR__) . '/inc/identity/auth_identities.php';

function fc_unit_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    fc_unit_assert(
        fc_contact_email_canonicalize('  Foo.Bar+Tag@Example.COM ') === 'foo.bar+tag@example.com',
        'Email canonicalization changed dots/plus addressing or failed trim/lowercase.'
    );

    $safe = fc_redact_sensitive_context([
        'safe' => 'keep',
        'access_token' => 'remove-me',
        'pkce_verifier_secret_envelope' => 'remove-ciphertext-too',
        'nested' => [
            'raw_session_id' => 'remove-me-too',
            'safe_nested' => true,
        ],
    ]);
    fc_unit_assert(($safe['safe'] ?? null) === 'keep', 'Safe log/audit metadata was not preserved.');
    fc_unit_assert(!array_key_exists('access_token', $safe), 'Sensitive access_token key was not removed.');
    fc_unit_assert(!array_key_exists('pkce_verifier_secret_envelope', $safe), 'Protected PKCE envelope must not enter ordinary logs/audit metadata.');
    fc_unit_assert(!array_key_exists('raw_session_id', $safe['nested'] ?? []), 'Nested sensitive key was not removed.');

    fc_unit_assert(fc_auth_destination_path('APP_HOME') === '/app.php', 'Approved destination mapping failed.');
    try {
        fc_auth_destination_path('https://evil.example/');
        throw new RuntimeException('Arbitrary redirect destination was accepted.');
    } catch (InvalidArgumentException $expected) {
        // expected
    }

    fc_unit_assert(
        fc_contract_value('google', FC_AUTH_PROVIDERS, 'provider') === 'GOOGLE',
        'Provider normalization failed.'
    );

    fc_unit_assert(fc_provider_email_verified_claim(true) === 1, 'Provider email TRUE claim was not preserved.');
    fc_unit_assert(fc_provider_email_verified_claim(false) === 0, 'Provider email FALSE claim was not preserved.');
    fc_unit_assert(fc_provider_email_verified_claim(null) === null, 'Provider email UNKNOWN claim was not preserved.');
    fc_unit_assert(fc_provider_email_verified_claim('UNKNOWN') === null, 'Provider email UNKNOWN string was not preserved.');

    $testKey = base64_encode(str_repeat("\x42", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $rawSecret = 'phase2a2-unit-pkce-' . bin2hex(random_bytes(16));
    $envelope = fc_protected_secret_encrypt($rawSecret, $testKey);
    fc_unit_assert(!str_contains($envelope, $rawSecret), 'Protected secret envelope exposed raw PKCE material.');
    fc_unit_assert(fc_protected_secret_decrypt($envelope, $testKey) === $rawSecret, 'Protected secret did not round-trip exactly.');

    $governance = (string) file_get_contents(dirname(__DIR__) . '/docs/governance_decisions.md');
    foreach ([
        'GOOGLE / APPLE / MICROSOFT',
        'FEDERATED / PASSWORDLESS FIRST',
        'SUPERSEDED',
        'Health Data Architecture v1.0',
        'Capability & Provenance Proof AUTHORIZED',
        'Private Measurements',
        'Crew-Shared Measurements',
    ] as $requiredGovernanceText) {
        fc_unit_assert(
            str_contains($governance, $requiredGovernanceText),
            'Governance decisions document is missing current canonical text: ' . $requiredGovernanceText
        );
    }

    $envExample = (string) file_get_contents(dirname(__DIR__) . '/.env.example');
    fc_unit_assert(
        preg_match('/^AUTH_TRANSACTION_SECRET_KEY_B64=\s*$/m', $envExample) === 1,
        '.env.example must expose an empty AUTH_TRANSACTION_SECRET_KEY_B64 contract.'
    );
    fc_unit_assert(
        preg_match('/^AUTH_TRANSACTION_TTL_SECONDS=600\s*$/m', $envExample) === 1,
        '.env.example must retain AUTH_TRANSACTION_TTL_SECONDS=600.'
    );

    $accountEntry = (string) file_get_contents(dirname(__DIR__) . '/views/auth/login.php');
    $googlePosition = strpos($accountEntry, 'Continue with Google');
    $applePosition = strpos($accountEntry, 'Continue with Apple');
    $microsoftPosition = strpos($accountEntry, 'Continue with Microsoft');
    fc_unit_assert(
        $googlePosition !== false && $applePosition !== false && $microsoftPosition !== false
        && $googlePosition < $applePosition && $applePosition < $microsoftPosition,
        'Account-entry provider order must be Google → Apple → Microsoft.'
    );

    echo "Phase 2A2 contract unit proof: PASS\n";
    echo "- contact email canonicalization: PASS\n";
    echo "- security metadata redaction: PASS\n";
    echo "- post-auth destination allowlist: PASS\n";
    echo "- provider contract normalization: PASS\n";
    echo "- provider email TRUE/FALSE/UNKNOWN normalization: PASS\n";
    echo "- protected PKCE secret encryption/decryption: PASS\n";
    echo "- governance decision reconciliation: PASS\n";
    echo "- auth-transaction secret configuration contract: PASS\n";
    echo "- account-entry provider order Google → Apple → Microsoft: PASS\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
