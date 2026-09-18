<?php

declare(strict_types=1);

require_once __DIR__ . '/email_magic_link.php';

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

/** @return array{transaction_id:string,state:string,nonce:string,expires_at:string,client_id:string} */
function fc_google_create_login_transaction(
    PDO $pdo,
    string $browserBinding,
    string $destinationKey,
    ?array $continuation = null
): array {
    $clientId = fc_google_auth_client_id();
    if ($clientId === '') {
        throw new RuntimeException('GOOGLE_AUTH_CLIENT_ID is not configured.');
    }
    $state = fc_google_random_token();
    $nonce = fc_google_random_token();
    $ttlSeconds = null;
    if ($continuation !== null) {
        $remaining = (new DateTimeImmutable((string) $continuation['expires_at'], new DateTimeZone('UTC')))
            ->getTimestamp() - time();
        if ($remaining < 30) {
            throw new DomainException('invitation_continuation_invalid');
        }
        $ttlSeconds = min((int) fc_env('AUTH_TRANSACTION_TTL_SECONDS', 600), $remaining);
    }

    $transaction = fc_auth_transaction_create(
        $pdo,
        'LOGIN',
        'GOOGLE',
        null,
        $state,
        $browserBinding,
        $destinationKey,
        $nonce,
        null,
        $ttlSeconds
    );
    if ($continuation !== null) {
        fc_auth_crew_invitation_continuation_bind_login_transaction(
            $pdo,
            (string) $continuation['public_id'],
            (int) $transaction['id']
        );
    }

    return [
        'transaction_id' => (string) $transaction['public_id'],
        'state' => $state,
        'nonce' => $nonce,
        'expires_at' => (string) $transaction['expires_at'],
        'client_id' => $clientId,
    ];
}

/** @return array<string,mixed> */
function fc_google_validate_continuation_for_refresh(PDO $pdo, array $continuation): array
{
    $snapshot = fc_auth_crew_invitation_product_snapshot(
        $pdo,
        (string) $continuation['invitation_public_id'],
        (int) $continuation['invitation_generation'],
        false
    );
    if ($snapshot === null) {
        throw new DomainException('invitation_continuation_product_invalid');
    }

    return $continuation;
}

/** @return array{transaction_id:string,state:string,nonce:string,expires_at:string,client_id:string} */
function fc_google_prepare_login_transaction(PDO $pdo): array
{
    $browserBinding = fc_auth_browser_binding();
    $continuationPointer = fc_auth_crew_invitation_continuation_session_public_id();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $continuation = fc_auth_crew_invitation_continuation_login_context($pdo, true);
        if ($continuation !== null && (string) $continuation['continuation_status'] === 'LOGIN_BOUND') {
            $statement = $pdo->prepare(
                'SELECT id, intent, expected_provider, consumed_at, browser_session_binding_hash ' .
                'FROM auth_transactions WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $statement->execute([':id' => (int) $continuation['auth_transaction_id']]);
            $bound = $statement->fetch(PDO::FETCH_ASSOC);
            if (
                $bound === false
                || (string) $bound['intent'] !== 'LOGIN'
                || (string) $bound['expected_provider'] !== 'GOOGLE'
                || $bound['consumed_at'] !== null
                || !hash_equals(
                    (string) $bound['browser_session_binding_hash'],
                    fc_secret_evidence_hash($browserBinding)
                )
            ) {
                throw new DomainException('auth_provider_choice_in_progress');
            }
            fc_google_validate_continuation_for_refresh($pdo, $continuation);
            if (!fc_auth_transaction_retire_unused($pdo, (int) $bound['id'])) {
                throw new DomainException('auth_transaction_refresh_unavailable');
            }
            fc_auth_crew_invitation_continuation_release_login_transaction(
                $pdo,
                (int) $continuation['id'],
                (int) $bound['id']
            );
            $continuation['continuation_status'] = 'ISSUED';
            $continuation['auth_transaction_id'] = null;
        }

        $destinationKey = $continuation === null
            ? 'APP_HOME'
            : FC_AUTH_CREW_INVITATION_DESTINATION;
        $prepared = fc_google_create_login_transaction(
            $pdo,
            $browserBinding,
            $destinationKey,
            $continuation
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }
        if ($ownsTransaction && $continuation === null && $continuationPointer !== null) {
            fc_auth_crew_invitation_continuation_clear_session($continuationPointer);
        }

        return $prepared;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * Replaces an exact expiring/expired unused LOGIN transaction. The old state,
 * nonce and credential remain unusable; the caller must render a new Google
 * button and require another explicit click.
 *
 * @return array{transaction_id:string,state:string,nonce:string,expires_at:string,client_id:string}
 */
function fc_google_refresh_login_transaction(
    PDO $pdo,
    string $transactionPublicId,
    string $rawState,
    string $rawBrowserBinding,
    bool $expiredOnly = false
): array {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $old = fc_auth_transaction_find_unused_exact(
            $pdo,
            $transactionPublicId,
            'LOGIN',
            'GOOGLE',
            $rawState,
            $rawBrowserBinding,
            null,
            true
        );
        if ($old === null || empty($old['nonce_hash'])) {
            throw new DomainException('auth_transaction_refresh_unavailable');
        }

        $expiresAt = new DateTimeImmutable((string) $old['expires_at'], new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($expiredOnly && $expiresAt > $now) {
            throw new DomainException('auth_transaction_refresh_unavailable');
        }
        if (!$expiredOnly && $expiresAt > $now->modify('+90 seconds')) {
            throw new DomainException('auth_transaction_refresh_not_due');
        }

        $continuation = null;
        if ((string) $old['post_auth_destination_key'] === FC_AUTH_CREW_INVITATION_DESTINATION) {
            $continuation = fc_auth_crew_invitation_continuation_for_transaction(
                $pdo,
                (int) $old['id'],
                true
            );
            if ($continuation === null) {
                throw new DomainException('invitation_continuation_invalid');
            }
            fc_google_validate_continuation_for_refresh($pdo, $continuation);
        }

        if (!fc_auth_transaction_retire_unused($pdo, (int) $old['id'])) {
            throw new DomainException('auth_transaction_refresh_unavailable');
        }
        if ($continuation !== null) {
            fc_auth_crew_invitation_continuation_release_login_transaction(
                $pdo,
                (int) $continuation['id'],
                (int) $old['id']
            );
            $continuation['continuation_status'] = 'ISSUED';
            $continuation['auth_transaction_id'] = null;
        }

        $prepared = fc_google_create_login_transaction(
            $pdo,
            $rawBrowserBinding,
            (string) $old['post_auth_destination_key'],
            $continuation
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }

        return $prepared;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
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
 * @return array{issuer:string,provider_subject:string,email_at_provider:?string,provider_email_verified:?int,display_name:?string,nonce:string,hosted_domain:?string}
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

/** @return array{issuer:string,provider_subject:string,email_at_provider:?string,provider_email_verified:?int,display_name:?string,nonce:string,hosted_domain:?string} */
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

/** @return array{issuer:string,provider_subject:string,email_at_provider:?string,provider_email_verified:?int,display_name:?string,nonce:string,hosted_domain:?string} */
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
        'hosted_domain' => is_string($payload['hd'] ?? null) ? trim($payload['hd']) : null,
    ];
}

/**
 * Called only with claims from a freshly verified Google ID token. Google is
 * authoritative for verified Gmail mailboxes and verified Workspace accounts
 * with a signed hd claim; other provider email remains descriptive evidence.
 */
function fc_google_authoritative_email(array $claims): ?string
{
    if (!in_array($claims['issuer'] ?? null, FC_GOOGLE_ISSUERS, true)
        || ($claims['provider_email_verified'] ?? null) !== 1) {
        return null;
    }
    $email = $claims['email_at_provider'] ?? null;
    if (!is_string($email) || strlen(trim($email)) > 254
        || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    $email = fc_contact_email_canonicalize($email);
    $domain = substr($email, strrpos($email, '@') + 1);
    $hostedDomain = $claims['hosted_domain'] ?? null;
    $workspace = is_string($hostedDomain) && $hostedDomain !== ''
        && filter_var($hostedDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    return $domain === 'gmail.com' || $workspace ? $email : null;
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

        $invitationContinuation = null;
        if ((string) $transaction['post_auth_destination_key'] === FC_AUTH_CREW_INVITATION_DESTINATION) {
            $invitationContinuation = fc_auth_crew_invitation_continuation_for_transaction(
                $pdo,
                (int) $transaction['id'],
                true
            );
            if ($invitationContinuation === null) {
                throw new DomainException('invitation_continuation_invalid');
            }
        }

        $identity = fc_auth_identity_find_oidc(
            $pdo,
            'GOOGLE',
            (string) $claims['issuer'],
            (string) $claims['provider_subject'],
            true
        );
        // Resolve Google by issuer/sub first. Canonical ownership may be
        // established on this user, but can never move an existing identity or
        // select another user merely because an email matches.
        $verifiedEmail = fc_google_authoritative_email($claims);
        if ($verifiedEmail !== null) {
            $emailIdentity = fc_auth_identity_find_oidc(
                $pdo, 'EMAIL', FC_EMAIL_MAGIC_LINK_ISSUER, $verifiedEmail, true
            );
            $verifiedOwner = fc_contact_email_find_verified_owner($pdo, $verifiedEmail, true);
            foreach ([$emailIdentity, $verifiedOwner] as $existingOwner) {
                if ($existingOwner !== null
                    && ($identity === null || (int) $existingOwner['user_id'] !== (int) $identity['user_id'])) {
                    throw new DomainException('account_reconciliation_required');
                }
            }
        }
        $newAccount = false;

        if ($identity !== null) {
            if ((string) $identity['identity_status'] !== 'ACTIVE' || (string) $identity['account_status'] !== 'ACTIVE') {
                throw new DomainException('fitcrew_account_access_denied');
            }

            $user = fc_user_find_by_id($pdo, (int) $identity['user_id'], true);
            if ($user === null || (string) $user['account_status'] !== 'ACTIVE') {
                throw new DomainException('fitcrew_account_access_denied');
            }

            if ($invitationContinuation !== null) {
                $snapshot = fc_auth_crew_invitation_product_snapshot(
                    $pdo,
                    (string) $invitationContinuation['invitation_public_id'],
                    (int) $invitationContinuation['invitation_generation'],
                    false
                );
                if ($snapshot === null) {
                    throw new DomainException('invitation_continuation_product_invalid');
                }
            }
        } else {
            if ($invitationContinuation !== null) {
                $snapshot = fc_auth_crew_invitation_product_snapshot(
                    $pdo,
                    (string) $invitationContinuation['invitation_public_id'],
                    (int) $invitationContinuation['invitation_generation'],
                    true
                );
                if ($snapshot === null) {
                    throw new DomainException('prelaunch_invitation_denied');
                }
                fc_auth_crew_invitation_admission_claim(
                    $pdo,
                    (int) $invitationContinuation['id'],
                    (string) $invitationContinuation['invitation_public_id']
                );
            } elseif (!fc_google_prelaunch_allows_new_account($claims)) {
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

        if ($verifiedEmail !== null) {
            fc_contact_email_ensure_verified($pdo, (int) $user['id'], $verifiedEmail, 'GOOGLE_AUTH');
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

        if ($invitationContinuation !== null) {
            fc_auth_crew_invitation_continuation_mark_authenticated(
                $pdo,
                (int) $invitationContinuation['id'],
                (int) $user['id'],
                (int) $sessionRecord['id'],
                $newAccount
            );
            if ($newAccount) {
                fc_auth_crew_invitation_admission_complete(
                    $pdo,
                    (int) $invitationContinuation['id'],
                    (int) $user['id']
                );
            }
        }

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
        'transaction_refreshed',
        'credential_failed',
        'nonce_failed',
        'account_denied',
        'account_reconciliation_required',
        'prelaunch_denied',
        'invitation_failed',
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
