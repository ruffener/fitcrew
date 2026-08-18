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
        ' user_id, email, email_canonical, verification_status, verified_at, source_key, is_primary_for_contact ' .
        ') VALUES (:user_id, :email, :canonical, :verification_status, :verified_at, :source, :primary)'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':email' => $email,
        ':canonical' => $canonical,
        ':verification_status' => $verificationStatus,
        ':verified_at' => $verifiedAt,
        ':source' => $source,
        ':primary' => $primary ? 1 : 0,
    ]);

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
