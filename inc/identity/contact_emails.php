<?php

declare(strict_types=1);

function fc_contact_email_canonicalize(string $email): string
{
    $email = trim($email);
    if ($email === '') {
        throw new InvalidArgumentException('Contact email cannot be empty.');
    }

    return function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);
}

/** @return array<string,mixed>|null */
function fc_contact_email_find_verified_owner(
    PDO $pdo,
    string $email,
    bool $forUpdate = false
): ?array {
    $canonical = fc_contact_email_canonicalize($email);
    $sql =
        'SELECT c.*, u.public_id AS user_public_id, u.account_status ' .
        'FROM user_contact_emails c ' .
        'JOIN users u ON u.id = c.user_id ' .
        'WHERE c.verified_email_canonical = :canonical ' .
        '  AND c.verification_status = \'VERIFIED\' AND c.removed_at IS NULL ' .
        'LIMIT 1';
    if ($forUpdate) {
        if (!$pdo->inTransaction()) {
            throw new LogicException('Locking a verified email owner requires an active transaction.');
        }
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([':canonical' => $canonical]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/** @return array{id:int,email_canonical:string} */
function fc_contact_email_create(
    PDO $pdo,
    int $userId,
    string $email,
    string $source,
    bool $primary = false,
    string $verificationStatus = 'UNVERIFIED',
    ?string $verifiedAt = null
): array {
    $verificationStatus = fc_contract_value(
        $verificationStatus,
        FC_CONTACT_EMAIL_VERIFICATION_STATUSES,
        'contact email verification status'
    );
    $source = strtoupper(trim($source));
    if ($source === '') {
        throw new InvalidArgumentException('Contact email source is required.');
    }
    if (($verificationStatus === 'VERIFIED') !== ($verifiedAt !== null)) {
        throw new InvalidArgumentException('verified_at must be present exactly when contact email status is VERIFIED.');
    }

    $email = trim($email);
    $canonical = fc_contact_email_canonicalize($email);

    $statement = $pdo->prepare(
        'INSERT INTO user_contact_emails ( ' .
        ' user_id, email, email_canonical, verification_status, verified_at, source_key, is_primary_for_contact, ' .
        ' verified_email_canonical ' .
        ') VALUES ( ' .
        ' :user_id, :email, :canonical, :verification_status, :verified_at, :source, :primary, :verified_canonical ' .
        ')'
    );
    try {
        $statement->execute([
            ':user_id' => $userId,
            ':email' => $email,
            ':canonical' => $canonical,
            ':verification_status' => $verificationStatus,
            ':verified_at' => $verifiedAt,
            ':source' => $source,
            ':primary' => $primary ? 1 : 0,
            ':verified_canonical' => $verificationStatus === 'VERIFIED' ? $canonical : null,
        ]);
    } catch (PDOException $error) {
        if (
            $verificationStatus === 'VERIFIED'
            && (string) $error->getCode() === '23000'
            && (int) ($error->errorInfo[1] ?? 0) === 1062
        ) {
            throw new DomainException('canonical_verified_email_conflict', 0, $error);
        }
        throw $error;
    }

    return ['id' => (int) $pdo->lastInsertId(), 'email_canonical' => $canonical];
}

function fc_contact_email_set_primary(PDO $pdo, int $userId, int $contactEmailId): void
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $clear = $pdo->prepare(
            'UPDATE user_contact_emails SET is_primary_for_contact = 0 ' .
            'WHERE user_id = :user_id AND removed_at IS NULL AND is_primary_for_contact = 1'
        );
        $clear->execute([':user_id' => $userId]);

        $set = $pdo->prepare(
            'UPDATE user_contact_emails SET is_primary_for_contact = 1 ' .
            'WHERE id = :id AND user_id = :user_id AND removed_at IS NULL'
        );
        $set->execute([':id' => $contactEmailId, ':user_id' => $userId]);
        if ($set->rowCount() !== 1) {
            throw new RuntimeException('Contact email is not an active email owned by the requested user.');
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function fc_contact_email_ensure_verified(PDO $pdo, int $userId, string $emailSubject, string $source): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Verified email contact reconciliation requires an active transaction.');
    }

    $emailSubject = fc_contact_email_canonicalize($emailSubject);
    $source = strtoupper(trim($source));
    if ($source === '') {
        throw new InvalidArgumentException('Verified email source is required.');
    }

    $owner = fc_contact_email_find_verified_owner($pdo, $emailSubject, true);
    if ($owner !== null && (int) $owner['user_id'] !== $userId) {
        throw new DomainException('account_reconciliation_required');
    }

    $find = $pdo->prepare(
        'SELECT id, verification_status FROM user_contact_emails ' .
        'WHERE user_id = :user_id AND email_canonical = :email AND removed_at IS NULL LIMIT 1 FOR UPDATE'
    );
    $find->execute([':user_id' => $userId, ':email' => $emailSubject]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    if ($existing !== false) {
        $update = $pdo->prepare(
            'UPDATE user_contact_emails ' .
            'SET verification_status = \'VERIFIED\', verified_at = :verified_at, ' .
            '    verified_email_canonical = :email, source_key = :source ' .
            'WHERE id = :id AND user_id = :user_id'
        );
        try {
            $update->execute([
                ':verified_at' => $now,
                ':source' => $source,
                ':email' => $emailSubject,
                ':id' => (int) $existing['id'],
                ':user_id' => $userId,
            ]);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('canonical_verified_email_conflict', 0, $error);
            }
            throw $error;
        }
        return;
    }

    $primary = $pdo->prepare(
        'SELECT id FROM user_contact_emails ' .
        'WHERE user_id = :user_id AND removed_at IS NULL AND is_primary_for_contact = 1 ' .
        'LIMIT 1 FOR UPDATE'
    );
    $primary->execute([':user_id' => $userId]);
    fc_contact_email_create(
        $pdo,
        $userId,
        $emailSubject,
        $source,
        $primary->fetchColumn() === false,
        'VERIFIED',
        $now
    );
}
