<?php

declare(strict_types=1);

function fc_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $appEnv = strtolower((string) fc_env('APP_ENV', 'local'));
    $secure = $appEnv === 'production' ? true : (bool) fc_env('SESSION_SECURE', false);
    $httpOnly = true;
    $sameSite = 'Lax';
    $name = (string) fc_env('SESSION_NAME', 'fitcrew_session');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_name($name);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => $httpOnly,
        'samesite' => $sameSite,
    ]);

    session_start();
}

/** @return array{idle_seconds:int,absolute_seconds:int} */
function fc_session_timeout_policy(): array
{
    $appEnv = strtolower((string) fc_env('APP_ENV', 'local'));
    $idleRaw = fc_env('SESSION_IDLE_SECONDS');
    $absoluteRaw = fc_env('SESSION_ABSOLUTE_SECONDS');

    if ($appEnv === 'production' && ($idleRaw === null || $absoluteRaw === null)) {
        throw new RuntimeException('Production session idle and absolute timeout values must be explicitly configured.');
    }

    $idle = $idleRaw === null ? 3600 : (int) $idleRaw;
    $absolute = $absoluteRaw === null ? 86400 : (int) $absoluteRaw;

    if ($idle < 300 || $idle > 86400) {
        throw new InvalidArgumentException('SESSION_IDLE_SECONDS must be between 300 and 86400 seconds.');
    }

    if ($absolute < $idle || $absolute > 2592000) {
        throw new InvalidArgumentException('SESSION_ABSOLUTE_SECONDS must be at least the idle timeout and no more than 2592000 seconds.');
    }

    return ['idle_seconds' => $idle, 'absolute_seconds' => $absolute];
}

function fc_auth_browser_binding(): string
{
    $existing = $_SESSION['fitcrew_auth_browser_binding'] ?? null;
    if (is_string($existing) && strlen($existing) >= 32) {
        return $existing;
    }

    $binding = bin2hex(random_bytes(32));
    $_SESSION['fitcrew_auth_browser_binding'] = $binding;

    return $binding;
}

/** @return array{id:int,session_id_hash:string} */
function fc_establish_authenticated_session(
    PDO $pdo,
    int $userId,
    int $authIdentityId,
    ?string $userAgentSummary = null,
    ?string $rawClientNetworkEvidence = null
): array {
    $policy = fc_session_timeout_policy();

    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Unable to regenerate FitCrew session identifier after authentication.');
    }

    $rawSessionId = session_id();
    if ($rawSessionId === '') {
        throw new RuntimeException('FitCrew session identifier is unavailable after authentication.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $idleExpiresAt = $now->modify(sprintf('+%d seconds', $policy['idle_seconds']));
    $absoluteExpiresAt = $now->modify(sprintf('+%d seconds', $policy['absolute_seconds']));

    return fc_session_record_create(
        $pdo,
        $userId,
        $authIdentityId,
        $rawSessionId,
        $idleExpiresAt,
        $absoluteExpiresAt,
        $userAgentSummary,
        $rawClientNetworkEvidence
    );
}

function fc_destroy_local_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => (string) ($params['samesite'] ?? 'Lax'),
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** @return array{id:int,session_id_hash:string} */
function fc_session_record_create_with_policy(
    PDO $pdo,
    int $userId,
    int $authIdentityId,
    string $rawSessionId,
    ?string $userAgentSummary = null,
    ?string $rawClientNetworkEvidence = null
): array {
    $policy = fc_session_timeout_policy();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return fc_session_record_create(
        $pdo,
        $userId,
        $authIdentityId,
        $rawSessionId,
        $now->modify(sprintf('+%d seconds', $policy['idle_seconds'])),
        $now->modify(sprintf('+%d seconds', $policy['absolute_seconds'])),
        $userAgentSummary,
        $rawClientNetworkEvidence
    );
}
