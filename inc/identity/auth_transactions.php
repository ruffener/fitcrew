<?php

declare(strict_types=1);

/** @return array{id:int,public_id:string} */
function fc_auth_transaction_create(
    PDO $pdo,
    string $intent,
    string $provider,
    ?int $expectedUserId,
    string $rawState,
    string $rawBrowserSessionBinding,
    string $destinationKey,
    ?string $rawNonce = null,
    ?string $rawPkceVerifier = null,
    ?int $ttlSeconds = null
): array {
    $intent = fc_contract_value($intent, FC_AUTH_TRANSACTION_INTENTS, 'auth transaction intent');
    $provider = fc_contract_value($provider, FC_AUTH_PROVIDERS, 'auth provider');
    $destinationKey = strtoupper(trim($destinationKey));
    fc_auth_destination_path($destinationKey);

    if (($intent === 'LOGIN') !== ($expectedUserId === null)) {
        throw new InvalidArgumentException('LOGIN transactions must not bind a user; LINK_IDENTITY/REAUTHENTICATE must bind one.');
    }

    $ttlSeconds ??= (int) fc_env('AUTH_TRANSACTION_TTL_SECONDS', 600);
    if ($ttlSeconds < 30 || $ttlSeconds > 1800) {
        throw new InvalidArgumentException('Auth transaction TTL must be between 30 and 1800 seconds.');
    }

    $publicId = fc_new_public_id();
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify(sprintf('+%d seconds', $ttlSeconds));

    $statement = $pdo->prepare(
        'INSERT INTO auth_transactions ( ' .
        ' public_id, intent, expected_provider, expected_user_id, state_hash, nonce_hash, pkce_verifier_hash, ' .
        ' browser_session_binding_hash, post_auth_destination_key, expires_at ' .
        ') VALUES ( ' .
        ' :public_id, :intent, :provider, :expected_user_id, :state_hash, :nonce_hash, :pkce_hash, ' .
        ' :browser_hash, :destination_key, :expires_at ' .
        ')'
    );
    $statement->execute([
        ':public_id' => $publicId,
        ':intent' => $intent,
        ':provider' => $provider,
        ':expected_user_id' => $expectedUserId,
        ':state_hash' => fc_secret_evidence_hash($rawState),
        ':nonce_hash' => $rawNonce !== null ? fc_secret_evidence_hash($rawNonce) : null,
        ':pkce_hash' => $rawPkceVerifier !== null ? fc_secret_evidence_hash($rawPkceVerifier) : null,
        ':browser_hash' => fc_secret_evidence_hash($rawBrowserSessionBinding),
        ':destination_key' => $destinationKey,
        ':expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
    ]);

    return ['id' => (int) $pdo->lastInsertId(), 'public_id' => $publicId];
}

function fc_auth_transaction_consume(
    PDO $pdo,
    string $publicId,
    string $intent,
    string $provider,
    string $rawState,
    string $rawBrowserSessionBinding,
    ?int $expectedUserId
): bool {
    $intent = fc_contract_value($intent, FC_AUTH_TRANSACTION_INTENTS, 'auth transaction intent');
    $provider = fc_contract_value($provider, FC_AUTH_PROVIDERS, 'auth provider');

    $sql =
        'UPDATE auth_transactions SET consumed_at = CURRENT_TIMESTAMP(6) ' .
        'WHERE public_id = :public_id ' .
        '  AND intent = :intent ' .
        '  AND expected_provider = :provider ' .
        '  AND state_hash = :state_hash ' .
        '  AND browser_session_binding_hash = :browser_hash ' .
        '  AND consumed_at IS NULL ' .
        '  AND expires_at > CURRENT_TIMESTAMP(6) ';

    $params = [
        ':public_id' => $publicId,
        ':intent' => $intent,
        ':provider' => $provider,
        ':state_hash' => fc_secret_evidence_hash($rawState),
        ':browser_hash' => fc_secret_evidence_hash($rawBrowserSessionBinding),
    ];

    if ($expectedUserId === null) {
        $sql .= '  AND expected_user_id IS NULL';
    } else {
        $sql .= '  AND expected_user_id = :expected_user_id';
        $params[':expected_user_id'] = $expectedUserId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->rowCount() === 1;
}
