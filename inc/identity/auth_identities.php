<?php

declare(strict_types=1);

/** @return array{id:int} */
function fc_auth_identity_create(PDO $pdo, int $userId, array $identity): array
{
    $provider = fc_contract_value((string) ($identity['provider_key'] ?? ''), FC_AUTH_PROVIDERS, 'auth provider');
    $status = fc_contract_value(
        (string) ($identity['identity_status'] ?? 'ACTIVE'),
        FC_AUTH_IDENTITY_STATUSES,
        'identity status'
    );
    $issuer = trim((string) ($identity['issuer'] ?? ''));
    if ($issuer === '') {
        throw new InvalidArgumentException('Authentication identity issuer is required.');
    }

    $providerSubject = fc_nullable_trimmed($identity['provider_subject'] ?? null);
    $tenantId = fc_nullable_trimmed($identity['provider_tenant_id'] ?? null);
    $objectId = fc_nullable_trimmed($identity['provider_object_id'] ?? null);
    $protocolSubject = fc_nullable_trimmed($identity['protocol_subject'] ?? null);

    if (in_array($provider, ['GOOGLE', 'APPLE'], true)) {
        if ($providerSubject === null || $tenantId !== null || $objectId !== null) {
            throw new InvalidArgumentException('Google/Apple identities require issuer + provider subject and no tenant/object identity.');
        }
    } elseif ($tenantId === null || $objectId === null) {
        throw new InvalidArgumentException('Microsoft identities require tenant ID + object ID.');
    }

    $providerEmailVerified = fc_provider_email_verified_claim($identity['provider_email_verified'] ?? null);
    $verificationObservedAt = $identity['email_verification_observed_at'] ?? null;
    if ($verificationObservedAt instanceof DateTimeInterface) {
        $verificationObservedAt = $verificationObservedAt->format('Y-m-d H:i:s.u');
    } elseif ($verificationObservedAt !== null) {
        $verificationObservedAt = trim((string) $verificationObservedAt);
        if ($verificationObservedAt === '') {
            $verificationObservedAt = null;
        }
    }

    $statement = $pdo->prepare(
        'INSERT INTO user_auth_identities ( ' .
        ' user_id, provider_key, issuer, provider_subject, provider_tenant_id, provider_object_id, protocol_subject, ' .
        ' email_at_provider, provider_email_verified, email_verification_observed_at, identity_status ' .
        ') VALUES ( ' .
        ' :user_id, :provider_key, :issuer, :provider_subject, :provider_tenant_id, :provider_object_id, :protocol_subject, ' .
        ' :email_at_provider, :provider_email_verified, :email_verification_observed_at, :identity_status ' .
        ')'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':provider_key' => $provider,
        ':issuer' => $issuer,
        ':provider_subject' => $providerSubject,
        ':provider_tenant_id' => $tenantId,
        ':provider_object_id' => $objectId,
        ':protocol_subject' => $protocolSubject,
        ':email_at_provider' => fc_nullable_trimmed($identity['email_at_provider'] ?? null),
        ':provider_email_verified' => $providerEmailVerified,
        ':email_verification_observed_at' => $verificationObservedAt,
        ':identity_status' => $status,
    ]);

    return ['id' => (int) $pdo->lastInsertId()];
}

function fc_provider_email_verified_claim(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }

    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if ($value === 1 || $value === '1') {
        return 1;
    }

    if ($value === 0 || $value === '0') {
        return 0;
    }

    if (is_string($value)) {
        return match (strtoupper(trim($value))) {
            'TRUE' => 1,
            'FALSE' => 0,
            'UNKNOWN', '' => null,
            default => throw new InvalidArgumentException('Provider email verification claim must be TRUE, FALSE, or UNKNOWN.'),
        };
    }

    throw new InvalidArgumentException('Provider email verification claim must be TRUE, FALSE, or UNKNOWN.');
}

function fc_nullable_trimmed(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);
    return $value === '' ? null : $value;
}
