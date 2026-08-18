<?php

declare(strict_types=1);

const FC_PROTECTED_SECRET_VERSION = 'v1';

function fc_auth_transaction_secret_key(?string $encodedKey = null): string
{
    if (!function_exists('sodium_crypto_secretbox') || !function_exists('sodium_crypto_secretbox_open')) {
        throw new RuntimeException('PHP Sodium support is required for protected authentication transaction secrets.');
    }

    $encodedKey ??= trim((string) fc_env('AUTH_TRANSACTION_SECRET_KEY_B64', ''));
    if ($encodedKey === '') {
        throw new RuntimeException('AUTH_TRANSACTION_SECRET_KEY_B64 is required when recoverable PKCE verifier storage is used.');
    }

    $key = base64_decode($encodedKey, true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException(sprintf(
            'AUTH_TRANSACTION_SECRET_KEY_B64 must decode to exactly %d bytes.',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        ));
    }

    return $key;
}

function fc_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function fc_base64url_decode(string $value): string
{
    $padding = strlen($value) % 4;
    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) {
        throw new RuntimeException('Protected secret envelope is not valid base64url data.');
    }

    return $decoded;
}

function fc_protected_secret_encrypt(string $plaintext, ?string $encodedKey = null): string
{
    if ($plaintext === '') {
        throw new InvalidArgumentException('Protected secret plaintext cannot be empty.');
    }

    $key = fc_auth_transaction_secret_key($encodedKey);
    try {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return FC_PROTECTED_SECRET_VERSION . '.' . fc_base64url_encode($nonce . $ciphertext);
    } finally {
        sodium_memzero($key);
    }
}

function fc_protected_secret_decrypt(string $envelope, ?string $encodedKey = null): string
{
    [$version, $payload] = array_pad(explode('.', $envelope, 2), 2, null);
    if ($version !== FC_PROTECTED_SECRET_VERSION || $payload === null || $payload === '') {
        throw new RuntimeException('Unsupported protected secret envelope format.');
    }

    $packed = fc_base64url_decode($payload);
    if (strlen($packed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('Protected secret envelope is incomplete.');
    }

    $nonce = substr($packed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($packed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key = fc_auth_transaction_secret_key($encodedKey);

    try {
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($plaintext === false) {
            throw new RuntimeException('Protected secret authentication failed.');
        }

        return $plaintext;
    } finally {
        sodium_memzero($key);
    }
}
