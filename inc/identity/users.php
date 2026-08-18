<?php

declare(strict_types=1);

/** @return array{id:int,public_id:string} */
function fc_user_create(
    PDO $pdo,
    ?string $displayName = null,
    string $accountStatus = 'ACTIVE',
    string $platformRole = 'USER',
    ?string $timezone = null,
    ?string $locale = null
): array {
    $accountStatus = fc_contract_value($accountStatus, FC_ACCOUNT_STATUSES, 'account status');
    $platformRole = fc_contract_value($platformRole, FC_PLATFORM_ROLES, 'platform role');
    $publicId = fc_new_public_id();

    $statement = $pdo->prepare(
        'INSERT INTO users (public_id, display_name, account_status, platform_role_code, timezone, locale) ' .
        'VALUES (:public_id, :display_name, :account_status, :platform_role_code, :timezone, :locale)'
    );
    $statement->execute([
        ':public_id' => $publicId,
        ':display_name' => $displayName !== null ? trim($displayName) : null,
        ':account_status' => $accountStatus,
        ':platform_role_code' => $platformRole,
        ':timezone' => $timezone !== null ? trim($timezone) : null,
        ':locale' => $locale !== null ? trim($locale) : null,
    ]);

    return ['id' => (int) $pdo->lastInsertId(), 'public_id' => $publicId];
}
