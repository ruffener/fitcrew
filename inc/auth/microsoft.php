<?php

declare(strict_types=1);

const FC_MICROSOFT_AUTHORITY = 'https://login.microsoftonline.com/common';
const FC_MICROSOFT_AUTHORIZE_ENDPOINT = FC_MICROSOFT_AUTHORITY . '/oauth2/v2.0/authorize';
const FC_MICROSOFT_TOKEN_ENDPOINT = FC_MICROSOFT_AUTHORITY . '/oauth2/v2.0/token';
const FC_MICROSOFT_JWKS_ENDPOINT = FC_MICROSOFT_AUTHORITY . '/discovery/v2.0/keys';
const FC_MICROSOFT_ISSUER_TEMPLATE = 'https://login.microsoftonline.com/{tenantid}/v2.0';
const FC_MICROSOFT_SCOPES = 'openid profile email';
const FC_MICROSOFT_CONSUMER_TENANT_ID = '9188040d-6c67-4c5b-b112-36a304b66dad';

/** @return array{enabled:bool,client_id:string,client_secret:string,redirect_uri:string} */
function fc_microsoft_auth_config(): array
{
    $clientId = strtolower(trim((string) fc_env('MICROSOFT_AUTH_CLIENT_ID', '')));
    $clientSecret = trim((string) fc_env('MICROSOFT_AUTH_CLIENT_SECRET', ''));
    $redirectUri = trim((string) fc_env('MICROSOFT_AUTH_REDIRECT_URI', ''));
    $enabled = (bool) fc_env('MICROSOFT_AUTH_ENABLED', false);

    return [
        'enabled' => $enabled,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
    ];
}

function fc_microsoft_auth_enabled(): bool
{
    $config = fc_microsoft_auth_config();
    if (!$config['enabled']) {
        return false;
    }

    try {
        fc_microsoft_validate_runtime_config($config);
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @param array{enabled:bool,client_id:string,client_secret:string,redirect_uri:string} $config */
function fc_microsoft_validate_runtime_config(array $config): void
{
    if (fc_microsoft_normalize_guid($config['client_id']) === null) {
        throw new RuntimeException('MICROSOFT_AUTH_CLIENT_ID must be a Microsoft application client GUID.');
    }

    if ($config['client_secret'] === '') {
        throw new RuntimeException('MICROSOFT_AUTH_CLIENT_SECRET is not configured.');
    }

    $redirect = parse_url($config['redirect_uri']);
    if (!is_array($redirect) || empty($redirect['scheme']) || empty($redirect['host']) || empty($redirect['path'])) {
        throw new RuntimeException('MICROSOFT_AUTH_REDIRECT_URI must be an absolute URL.');
    }

    $expectedPath = '/auth/microsoft/callback.php';
    if ((string) $redirect['path'] !== $expectedPath || isset($redirect['query']) || isset($redirect['fragment'])) {
        throw new RuntimeException('MICROSOFT_AUTH_REDIRECT_URI must target the governed Microsoft callback route exactly.');
    }

    $app = parse_url((string) fc_config()['url']);
    if (!is_array($app) || empty($app['scheme']) || empty($app['host'])) {
        throw new RuntimeException('APP_URL must be an absolute URL for Microsoft authentication.');
    }

    $redirectOrigin = strtolower((string) $redirect['scheme']) . '://' . strtolower((string) $redirect['host']);
    if (isset($redirect['port'])) {
        $redirectOrigin .= ':' . (int) $redirect['port'];
    }
    $appOrigin = strtolower((string) $app['scheme']) . '://' . strtolower((string) $app['host']);
    if (isset($app['port'])) {
        $appOrigin .= ':' . (int) $app['port'];
    }

    if (!hash_equals($appOrigin, $redirectOrigin)) {
        throw new RuntimeException('MICROSOFT_AUTH_REDIRECT_URI must use the configured FitCrew Challenge origin.');
    }

    if (strtolower((string) fc_env('APP_ENV', 'local')) === 'production' && strtolower((string) $redirect['scheme']) !== 'https') {
        throw new RuntimeException('Production Microsoft authentication requires an HTTPS redirect URI.');
    }
}

function fc_microsoft_random_token(int $bytes = 32): string
{
    return fc_base64url_encode(random_bytes($bytes));
}

function fc_microsoft_pkce_verifier(): string
{
    // 64 random bytes produces an 86-character base64url verifier, inside RFC 7636's 43-128 range.
    return fc_microsoft_random_token(64);
}

function fc_microsoft_pkce_challenge(string $verifier): string
{
    if (strlen($verifier) < 43 || strlen($verifier) > 128 || preg_match('/^[A-Za-z0-9._~-]+$/', $verifier) !== 1) {
        throw new InvalidArgumentException('Microsoft PKCE verifier does not meet the S256 verifier contract.');
    }

    return fc_base64url_encode(hash('sha256', $verifier, true));
}

function fc_microsoft_state_encode(string $transactionPublicId, string $rawState): string
{
    $transactionPublicId = trim($transactionPublicId);
    $rawState = trim($rawState);
    if ($transactionPublicId === '' || $rawState === '' || str_contains($transactionPublicId, '.') || str_contains($rawState, '.')) {
        throw new InvalidArgumentException('Microsoft auth state components are invalid.');
    }

    return $transactionPublicId . '.' . $rawState;
}

/** @return array{transaction_id:string,state:string}|null */
function fc_microsoft_state_decode(string $stateEnvelope): ?array
{
    $parts = explode('.', trim($stateEnvelope), 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return null;
    }

    if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $parts[0]) !== 1 || preg_match('/^[A-Za-z0-9_-]{32,256}$/', $parts[1]) !== 1) {
        return null;
    }

    return ['transaction_id' => $parts[0], 'state' => $parts[1]];
}

/** @return array{transaction_id:string,authorization_url:string} */
function fc_microsoft_prepare_login_transaction(PDO $pdo): array
{
    $config = fc_microsoft_auth_config();
    fc_microsoft_validate_runtime_config($config);

    $state = fc_microsoft_random_token();
    $nonce = fc_microsoft_random_token();
    $verifier = fc_microsoft_pkce_verifier();
    $transaction = fc_auth_transaction_create(
        $pdo,
        'LOGIN',
        'MICROSOFT',
        null,
        $state,
        fc_auth_browser_binding(),
        'APP_HOME',
        $nonce,
        $verifier
    );

    $stateEnvelope = fc_microsoft_state_encode($transaction['public_id'], $state);
    $query = http_build_query([
        'client_id' => $config['client_id'],
        'response_type' => 'code',
        'redirect_uri' => $config['redirect_uri'],
        // form_post keeps the short-lived authorization code out of the URL/access log.
        // The callback is a no-state bridge; it reposts to same-origin completion so SameSite=Lax remains intact.
        'response_mode' => 'form_post',
        'scope' => FC_MICROSOFT_SCOPES,
        'state' => $stateEnvelope,
        'nonce' => $nonce,
        'code_challenge' => fc_microsoft_pkce_challenge($verifier),
        'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986);

    return [
        'transaction_id' => $transaction['public_id'],
        'authorization_url' => FC_MICROSOFT_AUTHORIZE_ENDPOINT . '?' . $query,
    ];
}

function fc_microsoft_prelaunch_proof_mode(): bool
{
    return (bool) fc_env('PRELAUNCH_AUTH_PROOF_MODE', true);
}

/** @return list<string> */
function fc_microsoft_prelaunch_allowed_identities(): array
{
    $raw = trim((string) fc_env('PRELAUNCH_MICROSOFT_ALLOWED_IDENTITIES', ''));
    if ($raw === '') {
        return [];
    }

    $allowed = [];
    foreach (explode(',', $raw) as $entry) {
        $parts = explode(':', trim($entry), 2);
        if (count($parts) !== 2) {
            continue;
        }
        $tenantId = fc_microsoft_normalize_guid($parts[0]);
        $objectId = fc_microsoft_normalize_guid($parts[1]);
        if ($tenantId !== null && $objectId !== null) {
            $allowed[$tenantId . ':' . $objectId] = true;
        }
    }

    return array_keys($allowed);
}

function fc_microsoft_prelaunch_allows_new_account(array $claims): bool
{
    if (!fc_microsoft_prelaunch_proof_mode()) {
        return true;
    }

    $tenantId = fc_microsoft_normalize_guid((string) ($claims['provider_tenant_id'] ?? ''));
    $objectId = fc_microsoft_normalize_guid((string) ($claims['provider_object_id'] ?? ''));
    if ($tenantId === null || $objectId === null) {
        return false;
    }

    return in_array($tenantId . ':' . $objectId, fc_microsoft_prelaunch_allowed_identities(), true);
}

function fc_microsoft_normalize_guid(string $value): ?string
{
    $value = strtolower(trim($value));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1
        ? $value
        : null;
}

function fc_microsoft_expected_issuer(string $tenantId): string
{
    $tenantId = fc_microsoft_normalize_guid($tenantId) ?? '';
    if ($tenantId === '') {
        throw new DomainException('microsoft_tid_invalid');
    }

    return str_replace('{tenantid}', $tenantId, FC_MICROSOFT_ISSUER_TEMPLATE);
}

function fc_microsoft_signing_key_issuer_valid(string $keyIssuer, string $tenantId, string $tokenIssuer): bool
{
    $tenantId = fc_microsoft_normalize_guid($tenantId);
    $keyIssuer = trim($keyIssuer);
    $tokenIssuer = trim($tokenIssuer);
    if ($tenantId === null || $keyIssuer === '' || $tokenIssuer === '') {
        return false;
    }

    $resolved = str_ireplace('{tenantid}', $tenantId, $keyIssuer);
    return hash_equals($tokenIssuer, $resolved);
}

/** @return array{header:array<string,mixed>,payload:array<string,mixed>} */
function fc_microsoft_decode_unverified_jwt(string $rawIdToken): array
{
    $parts = explode('.', $rawIdToken);
    if (count($parts) !== 3) {
        throw new DomainException('microsoft_id_token_invalid');
    }

    try {
        $header = json_decode(fc_base64url_decode($parts[0]), true, 32, JSON_THROW_ON_ERROR);
        $payload = json_decode(fc_base64url_decode($parts[1]), true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new DomainException('microsoft_id_token_invalid');
    }

    if (!is_array($header) || !is_array($payload)) {
        throw new DomainException('microsoft_id_token_invalid');
    }

    return ['header' => $header, 'payload' => $payload];
}

/** @return array<string,mixed> */
function fc_microsoft_fetch_jwks(): array
{
    return fc_microsoft_http_json_get(FC_MICROSOFT_JWKS_ENDPOINT);
}

/** @return array<string,mixed> */
function fc_microsoft_http_json_get(string $url): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize Microsoft authentication HTTP client.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'FitCrewChallenge/MicrosoftAuth',
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if (!is_string($body) || $status < 200 || $status >= 300) {
        throw new RuntimeException('Microsoft authentication metadata is temporarily unavailable.');
    }

    try {
        $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new RuntimeException('Microsoft authentication metadata response is invalid.');
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('Microsoft authentication metadata response is invalid.');
    }

    return $decoded;
}

/** @param array<string,mixed> $response */
function fc_microsoft_code_exchange_exception_code(array $response): string
{
    $providerError = $response['error'] ?? null;
    if (!is_string($providerError)) {
        return 'microsoft_code_exchange_failed';
    }

    return match (strtolower(trim($providerError))) {
        'invalid_client' => 'microsoft_code_exchange_invalid_client',
        'invalid_grant' => 'microsoft_code_exchange_invalid_grant',
        'invalid_scope' => 'microsoft_code_exchange_invalid_scope',
        'unauthorized_client' => 'microsoft_code_exchange_unauthorized_client',
        'server_error', 'temporarily_unavailable' => 'microsoft_code_exchange_provider_unavailable',
        default => 'microsoft_code_exchange_failed',
    };
}

/** @return array<string,mixed> */
function fc_microsoft_http_post_form_json(string $url, array $fields): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new DomainException('microsoft_code_exchange_transport_failed');
    }

    $optionsSet = curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_USERAGENT => 'FitCrewChallenge/MicrosoftAuth',
    ]);
    if (!$optionsSet) {
        curl_close($curl);
        throw new DomainException('microsoft_code_exchange_transport_failed');
    }

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if (!is_string($body)) {
        throw new DomainException('microsoft_code_exchange_transport_failed');
    }

    try {
        $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new DomainException('microsoft_code_exchange_response_invalid');
    }

    if (!is_array($decoded)) {
        throw new DomainException('microsoft_code_exchange_response_invalid');
    }

    if ($status < 200 || $status >= 300) {
        // Retain only a fixed local classification. Microsoft error descriptions,
        // trace identifiers, codes, authorization codes, and tokens are discarded.
        throw new DomainException(fc_microsoft_code_exchange_exception_code($decoded));
    }

    if ($decoded === []) {
        throw new DomainException('microsoft_code_exchange_failed');
    }

    return $decoded;
}

/**
 * @param callable(string,array):array<string,mixed> $transport
 */
function fc_microsoft_exchange_authorization_code_with(
    string $authorizationCode,
    string $pkceVerifier,
    array $config,
    callable $transport
): string {
    $authorizationCode = trim($authorizationCode);
    if ($authorizationCode === '') {
        throw new DomainException('microsoft_authorization_code_invalid');
    }

    fc_microsoft_validate_runtime_config($config);
    // Re-validates verifier shape before a secret is sent to the token endpoint.
    fc_microsoft_pkce_challenge($pkceVerifier);

    $response = $transport(FC_MICROSOFT_TOKEN_ENDPOINT, [
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'grant_type' => 'authorization_code',
        'code' => $authorizationCode,
        'redirect_uri' => $config['redirect_uri'],
        'code_verifier' => $pkceVerifier,
        'scope' => FC_MICROSOFT_SCOPES,
    ]);

    $idToken = trim((string) ($response['id_token'] ?? ''));
    if ($idToken === '') {
        throw new DomainException('microsoft_id_token_missing');
    }

    // Access/refresh tokens, if present in the protocol response, are deliberately discarded here.
    return $idToken;
}

function fc_microsoft_exchange_authorization_code(string $authorizationCode, string $pkceVerifier): string
{
    $config = fc_microsoft_auth_config();
    return fc_microsoft_exchange_authorization_code_with(
        $authorizationCode,
        $pkceVerifier,
        $config,
        static fn (string $url, array $fields): array => fc_microsoft_http_post_form_json($url, $fields)
    );
}

/**
 * @param callable():array<string,mixed> $jwksFetcher
 * @return array{issuer:string,provider_tenant_id:string,provider_object_id:string,protocol_subject:string,email_at_provider:?string,provider_email_verified:?int,display_name:?string,nonce:string}
 */
function fc_microsoft_verify_id_token_with(
    string $rawIdToken,
    string $expectedClientId,
    string $expectedNonceHash,
    callable $jwksFetcher,
    ?int $now = null
): array {
    if ($rawIdToken === '') {
        throw new DomainException('microsoft_id_token_invalid');
    }
    if (!class_exists(\Firebase\JWT\JWT::class) || !class_exists(\Firebase\JWT\JWK::class)) {
        throw new RuntimeException('Firebase PHP-JWT dependency is unavailable for Microsoft authentication.');
    }

    $unverified = fc_microsoft_decode_unverified_jwt($rawIdToken);
    $header = $unverified['header'];
    $payload = $unverified['payload'];
    if (($header['alg'] ?? null) !== 'RS256') {
        throw new DomainException('microsoft_signing_algorithm_invalid');
    }
    $kid = trim((string) ($header['kid'] ?? ''));
    if ($kid === '') {
        throw new DomainException('microsoft_signing_key_invalid');
    }

    $tenantId = fc_microsoft_normalize_guid((string) ($payload['tid'] ?? ''));
    if ($tenantId === null) {
        throw new DomainException('microsoft_tid_invalid');
    }
    $tokenIssuer = trim((string) ($payload['iss'] ?? ''));
    if (!hash_equals(fc_microsoft_expected_issuer($tenantId), $tokenIssuer)) {
        throw new DomainException('microsoft_issuer_invalid');
    }

    $jwks = $jwksFetcher();
    $selectedJwk = null;
    foreach ((array) ($jwks['keys'] ?? []) as $candidate) {
        if (is_array($candidate) && hash_equals($kid, (string) ($candidate['kid'] ?? ''))) {
            $selectedJwk = $candidate;
            break;
        }
    }
    if (!is_array($selectedJwk)) {
        throw new DomainException('microsoft_signing_key_invalid');
    }

    $keyIssuer = trim((string) ($selectedJwk['issuer'] ?? ''));
    if (!fc_microsoft_signing_key_issuer_valid($keyIssuer, $tenantId, $tokenIssuer)) {
        throw new DomainException('microsoft_signing_key_issuer_invalid');
    }

    try {
        $key = \Firebase\JWT\JWK::parseKey($selectedJwk, 'RS256');
        if ($key === null) {
            throw new UnexpectedValueException('Unsupported signing key.');
        }
        $headers = new stdClass();
        $decoded = \Firebase\JWT\JWT::decode($rawIdToken, $key, $headers);
        $verifiedPayload = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new DomainException('microsoft_id_token_invalid');
    }

    if (!is_array($verifiedPayload)) {
        throw new DomainException('microsoft_id_token_invalid');
    }

    return fc_microsoft_validate_verified_payload(
        $verifiedPayload,
        $expectedClientId,
        $expectedNonceHash,
        $now ?? time(),
        $keyIssuer
    );
}

/** @return array{issuer:string,provider_tenant_id:string,provider_object_id:string,protocol_subject:string,email_at_provider:?string,provider_email_verified:?int,display_name:?string,nonce:string} */
function fc_microsoft_verify_id_token(string $rawIdToken, string $expectedNonceHash): array
{
    $config = fc_microsoft_auth_config();
    fc_microsoft_validate_runtime_config($config);

    return fc_microsoft_verify_id_token_with(
        $rawIdToken,
        $config['client_id'],
        $expectedNonceHash,
        static fn (): array => fc_microsoft_fetch_jwks()
    );
}

/** @return array{issuer:string,provider_tenant_id:string,provider_object_id:string,protocol_subject:string,email_at_provider:?string,provider_email_verified:?int,display_name:?string,nonce:string} */
function fc_microsoft_validate_verified_payload(
    array $payload,
    string $expectedClientId,
    string $expectedNonceHash,
    int $now,
    string $signingKeyIssuer
): array {
    if ((string) ($payload['ver'] ?? '') !== '2.0') {
        throw new DomainException('microsoft_token_version_invalid');
    }

    $audience = trim((string) ($payload['aud'] ?? ''));
    if ($audience === '' || !hash_equals(strtolower($expectedClientId), strtolower($audience))) {
        throw new DomainException('microsoft_audience_invalid');
    }

    $expiresAt = filter_var($payload['exp'] ?? null, FILTER_VALIDATE_INT);
    if ($expiresAt === false || (int) $expiresAt <= $now) {
        throw new DomainException('microsoft_id_token_expired');
    }
    $notBefore = filter_var($payload['nbf'] ?? null, FILTER_VALIDATE_INT);
    if ($notBefore !== false && (int) $notBefore > $now + 60) {
        throw new DomainException('microsoft_id_token_not_yet_valid');
    }

    $tenantId = fc_microsoft_normalize_guid((string) ($payload['tid'] ?? ''));
    if ($tenantId === null) {
        throw new DomainException('microsoft_tid_invalid');
    }
    $objectId = fc_microsoft_normalize_guid((string) ($payload['oid'] ?? ''));
    if ($objectId === null) {
        throw new DomainException('microsoft_oid_invalid');
    }

    $issuer = trim((string) ($payload['iss'] ?? ''));
    if (!hash_equals(fc_microsoft_expected_issuer($tenantId), $issuer)) {
        throw new DomainException('microsoft_issuer_invalid');
    }
    if (!fc_microsoft_signing_key_issuer_valid($signingKeyIssuer, $tenantId, $issuer)) {
        throw new DomainException('microsoft_signing_key_issuer_invalid');
    }

    $subject = trim((string) ($payload['sub'] ?? ''));
    if ($subject === '') {
        throw new DomainException('microsoft_subject_missing');
    }

    $nonce = trim((string) ($payload['nonce'] ?? ''));
    if ($nonce === '' || !hash_equals($expectedNonceHash, fc_secret_evidence_hash($nonce))) {
        throw new DomainException('microsoft_nonce_invalid');
    }

    $email = fc_nullable_trimmed($payload['email'] ?? null);
    if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $email = null;
    }
    $displayName = fc_nullable_trimmed($payload['name'] ?? null);
    if ($displayName !== null) {
        $displayName = substr($displayName, 0, 120);
    }

    return [
        'issuer' => $issuer,
        'provider_tenant_id' => $tenantId,
        'provider_object_id' => $objectId,
        'protocol_subject' => $subject,
        'email_at_provider' => $email,
        // Microsoft does not provide a general-purpose trustworthy email_verified claim in this flow.
        'provider_email_verified' => null,
        'display_name' => $displayName,
        'nonce' => $nonce,
    ];
}

/** @return array{user:array<string,mixed>,identity:array<string,mixed>,new_account:bool,destination:string} */
function fc_microsoft_complete_verified_login(
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
            'MICROSOFT',
            $rawState,
            $rawBrowserBinding,
            null,
            true
        );
        if ($transaction === null) {
            throw new DomainException('auth_transaction_invalid');
        }

        $identity = fc_auth_identity_find_microsoft(
            $pdo,
            (string) $claims['provider_tenant_id'],
            (string) $claims['provider_object_id'],
            true
        );
        $newAccount = false;

        if ($identity !== null) {
            if ((string) $identity['identity_status'] !== 'ACTIVE' || (string) $identity['account_status'] !== 'ACTIVE') {
                throw new DomainException('fitcrew_account_access_denied');
            }
            if (!hash_equals((string) $identity['issuer'], (string) $claims['issuer'])) {
                throw new DomainException('microsoft_identity_issuer_mismatch');
            }
            $storedProtocolSubject = fc_nullable_trimmed($identity['protocol_subject'] ?? null);
            if ($storedProtocolSubject !== null && !hash_equals($storedProtocolSubject, (string) $claims['protocol_subject'])) {
                throw new DomainException('microsoft_identity_subject_mismatch');
            }

            $user = fc_user_find_by_id($pdo, (int) $identity['user_id'], true);
            if ($user === null || (string) $user['account_status'] !== 'ACTIVE') {
                throw new DomainException('fitcrew_account_access_denied');
            }
        } else {
            if (!fc_microsoft_prelaunch_allows_new_account($claims)) {
                throw new DomainException('prelaunch_new_account_denied');
            }

            $created = fc_user_create($pdo, $claims['display_name'] ?? null, 'ACTIVE', 'USER');
            $user = fc_user_find_by_id($pdo, $created['id'], true);
            if ($user === null) {
                throw new RuntimeException('Unable to load newly created FitCrew user.');
            }

            $createdIdentity = fc_auth_identity_create($pdo, $created['id'], [
                'provider_key' => 'MICROSOFT',
                'issuer' => (string) $claims['issuer'],
                'provider_tenant_id' => (string) $claims['provider_tenant_id'],
                'provider_object_id' => (string) $claims['provider_object_id'],
                'protocol_subject' => (string) $claims['protocol_subject'],
                'email_at_provider' => $claims['email_at_provider'] ?? null,
                'provider_email_verified' => null,
                'identity_status' => 'ACTIVE',
            ]);
            $identity = fc_auth_identity_find_microsoft(
                $pdo,
                (string) $claims['provider_tenant_id'],
                (string) $claims['provider_object_id'],
                true
            );
            if ($identity === null || (int) $identity['id'] !== $createdIdentity['id']) {
                throw new RuntimeException('Unable to load newly created Microsoft identity.');
            }
            $newAccount = true;

            fc_audit_event_write($pdo, [
                'actor_user_id' => (int) $user['id'],
                'event_type' => 'ACCOUNT_CREATED_MICROSOFT',
                'target_type' => 'USER',
                'target_id' => (string) $user['public_id'],
                'outcome' => 'SUCCESS',
                'metadata' => ['provider' => 'MICROSOFT'],
            ]);
        }

        fc_auth_identity_update_provider_claims($pdo, (int) $identity['id'], [
            'email_at_provider' => $claims['email_at_provider'] ?? null,
        ]);

        $consumed = fc_auth_transaction_consume(
            $pdo,
            $transactionPublicId,
            'LOGIN',
            'MICROSOFT',
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
            'event_type' => 'MICROSOFT_AUTH_SUCCESS',
            'target_type' => 'USER',
            'target_id' => (string) $user['public_id'],
            'outcome' => 'SUCCESS',
            'metadata' => ['provider' => 'MICROSOFT', 'new_account' => $newAccount],
            'raw_client_evidence' => $rawClientNetworkEvidence,
        ]);
        fc_audit_event_write($pdo, [
            'actor_user_id' => (int) $user['id'],
            'event_type' => 'SESSION_CREATED',
            'target_type' => 'SESSION',
            'target_id' => (string) $sessionRecord['id'],
            'outcome' => 'SUCCESS',
            'metadata' => ['provider' => 'MICROSOFT'],
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

function fc_microsoft_expected_origin(): string
{
    $parts = parse_url((string) fc_config()['url']);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException('APP_URL must be an absolute URL for Microsoft authentication.');
    }

    $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
    if (isset($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }

    return $origin;
}

function fc_microsoft_request_origin_valid(?string $origin): bool
{
    return is_string($origin) && $origin !== '' && hash_equals(fc_microsoft_expected_origin(), strtolower(rtrim($origin, '/')));
}

function fc_microsoft_audit_rejection(PDO $pdo, string $reason, ?int $actorUserId = null): void
{
    $allowedReasons = [
        'csrf_failed',
        'origin_failed',
        'transaction_failed',
        'provider_error',
        'code_exchange_failed',
        'code_exchange_invalid_client',
        'code_exchange_invalid_grant',
        'code_exchange_invalid_scope',
        'code_exchange_unauthorized_client',
        'code_exchange_provider_unavailable',
        'code_exchange_transport_failed',
        'code_exchange_response_invalid',
        'pkce_failed',
        'token_failed',
        'nonce_failed',
        'issuer_failed',
        'identity_failed',
        'account_denied',
        'prelaunch_denied',
        'unexpected_failure',
    ];
    if (!in_array($reason, $allowedReasons, true)) {
        $reason = 'unexpected_failure';
    }

    fc_audit_event_write($pdo, [
        'actor_user_id' => $actorUserId,
        'event_type' => 'MICROSOFT_AUTH_REJECTED',
        'target_type' => 'AUTHENTICATION',
        'outcome' => 'DENIED',
        'metadata' => ['provider' => 'MICROSOFT', 'reason' => $reason],
    ]);
}
