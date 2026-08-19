<?php

declare(strict_types=1);

function fc_session_id_hash(string $rawSessionId): string
{
    return fc_secret_evidence_hash($rawSessionId);
}

function fc_assert_auth_identity_owned_by_user(PDO $pdo, int $userId, int $authIdentityId): void
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM user_auth_identities WHERE id = :identity_id AND user_id = :user_id LIMIT 1'
    );
    $statement->execute([
        ':identity_id' => $authIdentityId,
        ':user_id' => $userId,
    ]);

    if ($statement->fetchColumn() === false) {
        throw new DomainException('Authentication identity does not belong to the requested FitCrew user.');
    }
}

/** @return array{id:int,session_id_hash:string} */
function fc_session_record_create(
    PDO $pdo,
    int $userId,
    int $authIdentityId,
    string $rawSessionId,
    DateTimeInterface $idleExpiresAt,
    DateTimeInterface $absoluteExpiresAt,
    ?string $userAgentSummary = null,
    ?string $rawClientNetworkEvidence = null
): array {
    if ($absoluteExpiresAt < $idleExpiresAt) {
        throw new InvalidArgumentException('Absolute session expiry cannot be earlier than idle expiry.');
    }

    fc_assert_auth_identity_owned_by_user($pdo, $userId, $authIdentityId);

    $hash = fc_session_id_hash($rawSessionId);
    $statement = $pdo->prepare(
        'INSERT INTO user_sessions ( ' .
        ' user_id, auth_identity_id, session_id_hash, idle_expires_at, absolute_expires_at, ' .
        ' user_agent_summary, client_network_hash ' .
        ') VALUES ( ' .
        ' :user_id, :auth_identity_id, :session_hash, :idle_expires_at, :absolute_expires_at, ' .
        ' :user_agent_summary, :client_network_hash ' .
        ')'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':auth_identity_id' => $authIdentityId,
        ':session_hash' => $hash,
        ':idle_expires_at' => $idleExpiresAt->format('Y-m-d H:i:s.u'),
        ':absolute_expires_at' => $absoluteExpiresAt->format('Y-m-d H:i:s.u'),
        ':user_agent_summary' => $userAgentSummary !== null ? substr($userAgentSummary, 0, 255) : null,
        ':client_network_hash' => $rawClientNetworkEvidence !== null
            ? fc_secret_evidence_hash($rawClientNetworkEvidence)
            : null,
    ]);

    return ['id' => (int) $pdo->lastInsertId(), 'session_id_hash' => $hash];
}

function fc_session_revoke(PDO $pdo, string $rawSessionId, string $reason): bool
{
    $statement = $pdo->prepare(
        'UPDATE user_sessions SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP(6)), revocation_reason = :reason ' .
        'WHERE session_id_hash = :session_hash AND revoked_at IS NULL'
    );
    $statement->execute([
        ':reason' => substr(trim($reason), 0, 160),
        ':session_hash' => fc_session_id_hash($rawSessionId),
    ]);

    return $statement->rowCount() === 1;
}

function fc_session_revoke_all_for_user(PDO $pdo, int $userId, string $reason): int
{
    $statement = $pdo->prepare(
        'UPDATE user_sessions SET revoked_at = CURRENT_TIMESTAMP(6), revocation_reason = :reason ' .
        'WHERE user_id = :user_id AND revoked_at IS NULL'
    );
    $statement->execute([
        ':reason' => substr(trim($reason), 0, 160),
        ':user_id' => $userId,
    ]);

    return $statement->rowCount();
}

/** @return array<string,mixed>|null */
function fc_session_record_resolve_active(PDO $pdo, string $rawSessionId, int $idleSeconds): ?array
{
    if ($rawSessionId === '') {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT ' .
        ' s.id AS session_record_id, s.user_id, s.auth_identity_id, s.idle_expires_at, s.absolute_expires_at, ' .
        ' u.public_id, u.display_name, u.account_status, u.platform_role_code, u.timezone, u.locale, u.onboarding_completed_at, ' .
        ' i.provider_key, i.identity_status ' .
        'FROM user_sessions s ' .
        'JOIN users u ON u.id = s.user_id ' .
        'JOIN user_auth_identities i ON i.id = s.auth_identity_id AND i.user_id = s.user_id ' .
        'WHERE s.session_id_hash = :session_hash ' .
        '  AND s.revoked_at IS NULL ' .
        '  AND s.idle_expires_at > CURRENT_TIMESTAMP(6) ' .
        '  AND s.absolute_expires_at > CURRENT_TIMESTAMP(6) ' .
        'LIMIT 1'
    );
    $statement->execute([':session_hash' => fc_session_id_hash($rawSessionId)]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return null;
    }

    if ((string) $row['account_status'] !== 'ACTIVE' || (string) $row['identity_status'] !== 'ACTIVE') {
        fc_session_revoke($pdo, $rawSessionId, 'account_or_identity_inactive');
        return null;
    }

    $absolute = new DateTimeImmutable((string) $row['absolute_expires_at'], new DateTimeZone('UTC'));
    $candidateIdle = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify(sprintf('+%d seconds', $idleSeconds));
    $nextIdle = $candidateIdle < $absolute ? $candidateIdle : $absolute;

    $touch = $pdo->prepare(
        'UPDATE user_sessions SET last_seen_at = CURRENT_TIMESTAMP(6), idle_expires_at = :idle_expires_at ' .
        'WHERE id = :id AND revoked_at IS NULL'
    );
    $touch->execute([
        ':idle_expires_at' => $nextIdle->format('Y-m-d H:i:s.u'),
        ':id' => (int) $row['session_record_id'],
    ]);

    return $row;
}

function fc_session_count_active_for_user(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM user_sessions ' .
        'WHERE user_id = :user_id ' .
        '  AND revoked_at IS NULL ' .
        '  AND idle_expires_at > CURRENT_TIMESTAMP(6) ' .
        '  AND absolute_expires_at > CURRENT_TIMESTAMP(6)'
    );
    $statement->execute([':user_id' => $userId]);

    return (int) $statement->fetchColumn();
}
