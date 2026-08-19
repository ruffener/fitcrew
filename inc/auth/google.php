<?php

declare(strict_types=1);

const FC_GOOGLE_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

function fc_google_auth_client_id(): string
{
    return trim((string) fc_env('GOOGLE_AUTH_CLIENT_ID', ''));
}

function fc_google_auth_enabled(): bool
{
    return (bool) fc_env('GOOGLE_AUTH_ENABLED', false) && fc_google_auth_client_id() !== '';
}

function fc_google_random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** @return array{transaction_id:string,state:string,nonce:string,client_id:string} */
function fc_google_prepare_login_transaction(PDO $pdo): array
{
    $clientId = fc_google_auth_client_id();
    if ($clientId === '') {
        throw new RuntimeException('GOOGLE_AUTH_CLIENT_ID is not configured.');
    }

    $state = fc_google_random_token();
    $nonce = fc_google_random_token();
    $transaction = fc_auth_transaction_create(
        $pdo,
        'LOGIN',
        'GOOGLE',
        null,
        $state,
        fc_auth_browser_binding(),
        'APP_HOME',
        $nonce
    );

    return [
        'transaction_id' => $transaction['public_id'],
        'state' => $state,
        'nonce' => $nonce,
        'client_id' => $clientId,
    ];
}

function fc_google_prelaunch_proof_mode(): bool
{
    return (bool) fc_env('PRELAUNCH_AUTH_PROOF_MODE', true);
}

/** @return list<string> */
function fc_google_prelaunch_allowed_emails(): array
{
    $raw = trim((string) fc_env('PRELAUNCH_AUTH_ALLOWED_EMAILS', ''));
    if ($raw === '') {
        return [];
    }

    $emails = [];
    foreach (explode(',', $raw) as $value) {
        $email = strtolower(trim($value));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = true;
        }
    }

    return array_keys($emails);
}

function fc_google_prelaunch_allows_new_account(array $claims): bool
{
    if (!fc_google_prelaunch_proof_mode()) {
        return true;
    }

    $email = strtolower(trim((string) ($claims['email_at_provider'] ?? '')));
    $verified = $claims['provider_email_verified'] ?? null;

    if ($email === '' || $verified !== 1) {
        return false;
    }

    return in_array($email, fc_google_prelaunch_allowed_emails(), true);
}

/**
 * @param callable(string):array|false $verifier
 * @return array{issuer:string,provider_subject:string,email_at_provider:?string,provider_email_verified:?int,display_name:?string,nonce:string}
 */
function fc_google_verify_id_token_with(
    string $rawIdToken,
    string $expectedClientId,
    string $expectedNonceHash,
    callable $verifier,
    ?int $now = null
): array {
    if ($rawIdToken === '') {
        throw new DomainException('google_credential_invalid');
    }

    $payload = $verifier($rawIdToken);
    if (!is_array($payload)) {
        throw new DomainException('google_credential_invalid');
    }

    return fc_google_validate_verified_payload($payload, $expectedClientId, $expectedNonceHash, $now ?? time());
}

/** @return array{issuer:string,provider_subject:string,email_at_provider:?string,provider_email_verified:?int,display_name:?string,nonce:string} */
function fc_google_verify_id_token(string $rawIdToken, string $expectedNonceHash): array
{
    $clientId = fc_google_auth_client_id();
    if ($clientId === '') {
        throw new RuntimeException('Google authentication client ID is not configured.');
    }

    if (!class_exists(\Google\Client::class)) {
        throw new RuntimeException('Google API Client Library for PHP is not installed.');
    }

    $client = new \Google\Client();
    $client->setClientId($clientId);

    try {
        return fc_google_verify_id_token_with(
            $rawIdToken,
            $clientId,
            $expectedNonceHash,
            static fn (string $token): array|false => $client->verifyIdToken($token)
        );
    } catch (DomainException $e) {
        throw $e;
    } catch (Throwable) {
        throw new DomainException('google_credential_invalid');
    }
}

/** @return array{issuer:string,provider_subject:string,email_at_provider:?string,provider_email_verified:?int,display_name:?string,nonce:string} */
function fc_google_validate_verified_payload(array $payload, string $expectedClientId, string $expectedNonceHash, int $now): array
{
    $issuer = trim((string) ($payload['iss'] ?? ''));
    if (!in_array($issuer, FC_GOOGLE_ISSUERS, true)) {
        throw new DomainException('google_issuer_invalid');
    }

    $audience = $payload['aud'] ?? null;
    $audienceValid = is_string($audience)
        ? hash_equals($expectedClientId, $audience)
        : (is_array($audience) && in_array($expectedClientId, $audience, true));
    if (!$audienceValid) {
        throw new DomainException('google_audience_invalid');
    }

    $expiresAt = filter_var($payload['exp'] ?? null, FILTER_VALIDATE_INT);
    if ($expiresAt === false || (int) $expiresAt <= $now) {
        throw new DomainException('google_credential_expired');
    }

    $subject = trim((string) ($payload['sub'] ?? ''));
    if ($subject === '') {
        throw new DomainException('google_subject_missing');
    }

    $nonce = trim((string) ($payload['nonce'] ?? ''));
    if ($nonce === '' || !hash_equals($expectedNonceHash, fc_secret_evidence_hash($nonce))) {
        throw new DomainException('google_nonce_invalid');
    }

    $email = fc_nullable_trimmed($payload['email'] ?? null);
    $verifiedClaim = array_key_exists('email_verified', $payload)
        ? fc_provider_email_verified_claim($payload['email_verified'])
        : null;
    $displayName = fc_nullable_trimmed($payload['name'] ?? null);
    if ($displayName !== null) {
        $displayName = substr($displayName, 0, 120);
    }

    return [
        'issuer' => $issuer,
        'provider_subject' => $subject,
        'email_at_provider' => $email,
        'provider_email_verified' => $verifiedClaim,
        'display_name' => $displayName,
        'nonce' => $nonce,
    ];
}

/** @return array{user:array<string,mixed>,identity:array<string,mixed>,new_account:bool,destination:string} */
function fc_google_complete_verified_login(
    PDO $pdo,
    string $transactionPublicId,
    string $rawState,
    string $rawBrowserBinding,
    array $claims,
    string $rawSessionId,
    ?string $userAgentSummary = null,
    ?string $rawClientNetworkEvidence = null
): array {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $transaction = fc_auth_transaction_find_valid(
            $pdo,
            $transactionPublicId,
            'LOGIN',
            'GOOGLE',
            $rawState,
            $rawBrowserBinding,
            null,
            true
        );
        if ($transaction === null) {
            throw new DomainException('auth_transaction_invalid');
        }

        $identity = fc_auth_identity_find_oidc(
            $pdo,
            'GOOGLE',
            (string) $claims['issuer'],
            (string) $claims['provider_subject'],
            true
        );
        $newAccount = false;

        if ($identity !== null) {
            if ((string) $identity['identity_status'] !== 'ACTIVE' || (string) $identity['account_status'] !== 'ACTIVE') {
                throw new DomainException('fitcrew_account_access_denied');
            }

            $user = fc_user_find_by_id($pdo, (int) $identity['user_id'], true);
            if ($user === null || (string) $user['account_status'] !== 'ACTIVE') {
                throw new DomainException('fitcrew_account_access_denied');
            }
        } else {
            if (!fc_google_prelaunch_allows_new_account($claims)) {
                throw new DomainException('prelaunch_new_account_denied');
            }

            $created = fc_user_create(
                $pdo,
                $claims['display_name'] ?? null,
                'ACTIVE',
                'USER'
            );
            $user = fc_user_find_by_id($pdo, $created['id'], true);
            if ($user === null) {
                throw new RuntimeException('Unable to load newly created FitCrew user.');
            }

            $createdIdentity = fc_auth_identity_create($pdo, $created['id'], [
                'provider_key' => 'GOOGLE',
                'issuer' => (string) $claims['issuer'],
                'provider_subject' => (string) $claims['provider_subject'],
                'email_at_provider' => $claims['email_at_provider'] ?? null,
                'provider_email_verified' => $claims['provider_email_verified'] ?? null,
                'email_verification_observed_at' => array_key_exists('provider_email_verified', $claims)
                    ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
                    : null,
                'identity_status' => 'ACTIVE',
            ]);
            $identity = fc_auth_identity_find_oidc(
                $pdo,
                'GOOGLE',
                (string) $claims['issuer'],
                (string) $claims['provider_subject'],
                true
            );
            if ($identity === null || (int) $identity['id'] !== $createdIdentity['id']) {
                throw new RuntimeException('Unable to load newly created Google identity.');
            }
            $newAccount = true;

            fc_audit_event_write($pdo, [
                'actor_user_id' => (int) $user['id'],
                'event_type' => 'ACCOUNT_CREATED_GOOGLE',
                'target_type' => 'USER',
                'target_id' => (string) $user['public_id'],
                'outcome' => 'SUCCESS',
                'metadata' => ['provider' => 'GOOGLE'],
            ]);
        }

        fc_auth_identity_update_provider_claims($pdo, (int) $identity['id'], [
            'email_at_provider' => $claims['email_at_provider'] ?? null,
            'provider_email_verified' => $claims['provider_email_verified'] ?? null,
        ]);

        $consumed = fc_auth_transaction_consume(
            $pdo,
            $transactionPublicId,
            'LOGIN',
            'GOOGLE',
            $rawState,
            $rawBrowserBinding,
            null
        );
        if (!$consumed) {
            throw new DomainException('auth_transaction_already_consumed');
        }

        $sessionRecord = fc_session_record_create_with_policy(
            $pdo,
            (int) $user['id'],
            (int) $identity['id'],
            $rawSessionId,
            $userAgentSummary,
            $rawClientNetworkEvidence
        );

        fc_audit_event_write($pdo, [
            'actor_user_id' => (int) $user['id'],
            'event_type' => 'GOOGLE_AUTH_SUCCESS',
            'target_type' => 'USER',
            'target_id' => (string) $user['public_id'],
            'outcome' => 'SUCCESS',
            'metadata' => ['provider' => 'GOOGLE', 'new_account' => $newAccount],
            'raw_client_evidence' => $rawClientNetworkEvidence,
        ]);
        fc_audit_event_write($pdo, [
            'actor_user_id' => (int) $user['id'],
            'event_type' => 'SESSION_CREATED',
            'target_type' => 'SESSION',
            'target_id' => (string) $sessionRecord['id'],
            'outcome' => 'SUCCESS',
            'metadata' => ['provider' => 'GOOGLE'],
            'raw_client_evidence' => $rawClientNetworkEvidence,
        ]);

        $destination = fc_auth_destination_path((string) $transaction['post_auth_destination_key']);
        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'user' => $user,
            'identity' => $identity,
            'new_account' => $newAccount,
            'destination' => $destination,
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function fc_google_audit_rejection(PDO $pdo, string $reason, ?int $actorUserId = null): void
{
    $allowedReasons = [
        'csrf_failed',
        'origin_failed',
        'transaction_failed',
        'credential_failed',
        'nonce_failed',
        'account_denied',
        'prelaunch_denied',
        'unexpected_failure',
    ];
    if (!in_array($reason, $allowedReasons, true)) {
        $reason = 'unexpected_failure';
    }

    fc_audit_event_write($pdo, [
        'actor_user_id' => $actorUserId,
        'event_type' => 'GOOGLE_AUTH_REJECTED',
        'target_type' => 'AUTHENTICATION',
        'outcome' => 'DENIED',
        'metadata' => ['provider' => 'GOOGLE', 'reason' => $reason],
    ]);
}

function fc_google_expected_origin(): string
{
    $parts = parse_url((string) fc_config()['url']);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException('APP_URL must be an absolute URL for Google authentication.');
    }

    $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
    if (isset($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }

    return $origin;
}

function fc_google_request_origin_valid(?string $origin): bool
{
    return is_string($origin) && $origin !== '' && hash_equals(fc_google_expected_origin(), strtolower(rtrim($origin, '/')));
}
