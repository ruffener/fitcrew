<?php

declare(strict_types=1);

const FC_ACCOUNT_STATUSES = ['ACTIVE', 'SUSPENDED', 'DEACTIVATED'];
const FC_PLATFORM_ROLES = ['USER', 'PLATFORM_ADMIN'];
const FC_AUTH_PROVIDERS = ['GOOGLE', 'APPLE', 'MICROSOFT'];
const FC_AUTH_IDENTITY_STATUSES = ['ACTIVE', 'REVOKED', 'UNLINKED', 'DISABLED'];
const FC_CONTACT_EMAIL_VERIFICATION_STATUSES = ['UNVERIFIED', 'VERIFIED'];
const FC_AUTH_TRANSACTION_INTENTS = ['LOGIN', 'LINK_IDENTITY', 'REAUTHENTICATE'];
const FC_AUDIT_OUTCOMES = ['SUCCESS', 'FAILURE', 'DENIED'];

const FC_AUTH_DESTINATIONS = [
    'APP_HOME' => '/app.php',
    'ACCOUNT_ENTRY' => '/login.php',
];

function fc_contract_value(string $value, array $allowed, string $label): string
{
    $value = strtoupper(trim($value));
    if (!in_array($value, $allowed, true)) {
        throw new InvalidArgumentException(sprintf('Invalid %s: %s', $label, $value));
    }

    return $value;
}

function fc_auth_destination_path(string $key): string
{
    $key = strtoupper(trim($key));
    if (!array_key_exists($key, FC_AUTH_DESTINATIONS)) {
        throw new InvalidArgumentException(sprintf('Unapproved post-auth destination key: %s', $key));
    }

    return FC_AUTH_DESTINATIONS[$key];
}
