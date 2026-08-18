<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__) . '/inc/security/redaction.php';
require_once dirname(__DIR__) . '/inc/identity/contracts.php';
require_once dirname(__DIR__) . '/inc/identity/contact_emails.php';

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
        'nested' => [
            'raw_session_id' => 'remove-me-too',
            'safe_nested' => true,
        ],
    ]);
    fc_unit_assert(($safe['safe'] ?? null) === 'keep', 'Safe log/audit metadata was not preserved.');
    fc_unit_assert(!array_key_exists('access_token', $safe), 'Sensitive access_token key was not removed.');
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

    echo "Phase 2A2 contract unit proof: PASS\n";
    echo "- contact email canonicalization: PASS\n";
    echo "- security metadata redaction: PASS\n";
    echo "- post-auth destination allowlist: PASS\n";
    echo "- provider contract normalization: PASS\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
