<?php

declare(strict_types=1);

function fc_session_id_hash(string $rawSessionId): string
{
    return fc_secret_evidence_hash($rawSessionId);
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
