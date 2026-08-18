<?php

declare(strict_types=1);

/**
 * Security-sensitive keys that must never be written to ordinary logs or audit metadata.
 * Values are removed entirely rather than masked so downstream storage cannot accidentally
 * retain a secret under a known sensitive key.
 */
function fc_sensitive_context_key(string $key): bool
{
    $normalized = strtolower(trim($key));
    $normalized = str_replace(['-', ' ', '.'], '_', $normalized);

    static $blocked = [
        'password',
        'raw_session_id',
        'session_id',
        'oauth_code',
        'authorization_code',
        'state',
        'state_secret',
        'nonce',
        'oidc_nonce',
        'pkce_verifier',
        'access_token',
        'provider_access_token',
        'refresh_token',
        'provider_refresh_token',
        'health_provider_token',
        'raw_health_payload',
    ];

    return in_array($normalized, $blocked, true);
}

/** @return array<mixed> */
function fc_redact_sensitive_context(array $context): array
{
    $safe = [];

    foreach ($context as $key => $value) {
        if (is_string($key) && fc_sensitive_context_key($key)) {
            continue;
        }

        $safe[$key] = is_array($value)
            ? fc_redact_sensitive_context($value)
            : $value;
    }

    return $safe;
}

function fc_secret_evidence_hash(string $value): string
{
    if ($value === '') {
        throw new InvalidArgumentException('Security evidence cannot be empty.');
    }

    return hash('sha256', $value);
}
