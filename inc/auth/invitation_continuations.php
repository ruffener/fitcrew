<?php

declare(strict_types=1);

const FC_AUTH_CREW_INVITATION_PURPOSE = 'CREW_INVITATION_ACCEPTANCE';
const FC_AUTH_CREW_INVITATION_DESTINATION = 'CREW_INVITATION_ACCEPTANCE';
const FC_AUTH_CREW_INVITATION_SESSION_KEY = 'fitcrew_crew_invitation_continuation';
const FC_AUTH_CREW_INVITATION_MAX_TTL_SECONDS = 1800;

/**
 * Website-owned callable contract:
 *
 * fc_crew_invitation_auth_snapshot(
 *     PDO $pdo,
 *     string $invitationPublicId,
 *     int $expectedGeneration,
 *     bool $lockForAdmission = false
 * ): ?array
 *
 * The returned array must contain exactly the invitation public ID, generation,
 * and expiration. It must never contain the raw token, token hash, email, Crew
 * membership data, or provider identity data.
 *
 * @return array{invitation_public_id:string,generation:int,expires_at:string}|null
 */
function fc_auth_crew_invitation_product_snapshot(
    PDO $pdo,
    string $invitationPublicId,
    int $expectedGeneration,
    bool $lockForAdmission = false
): ?array {
    if (!function_exists('fc_crew_invitation_auth_snapshot')) {
        $interfacePath = fc_path('inc/product/crew_invitations.php');
        if (is_file($interfacePath)) {
            require_once $interfacePath;
        }
    }

    if (!function_exists('fc_crew_invitation_auth_snapshot')) {
        throw new RuntimeException('Website invitation validation interface is unavailable.');
    }

    if ($lockForAdmission && !$pdo->inTransaction()) {
        throw new LogicException('Invitation admission validation requires an active database transaction.');
    }

    $snapshot = fc_crew_invitation_auth_snapshot(
        $pdo,
        $invitationPublicId,
        $expectedGeneration,
        $lockForAdmission
    );
    if ($snapshot === null) {
        return null;
    }
    if (!is_array($snapshot)) {
        throw new RuntimeException('Website invitation validation returned an invalid result.');
    }

    $allowedKeys = ['invitation_public_id', 'generation', 'expires_at'];
    $returnedKeys = array_keys($snapshot);
    sort($allowedKeys);
    sort($returnedKeys);
    if ($returnedKeys !== $allowedKeys) {
        throw new RuntimeException('Website invitation validation crossed the approved data boundary.');
    }

    $publicId = trim((string) ($snapshot['invitation_public_id'] ?? ''));
    $generation = filter_var($snapshot['generation'] ?? null, FILTER_VALIDATE_INT);
    $expiresAt = trim((string) ($snapshot['expires_at'] ?? ''));
    if (
        !fc_public_id_is_valid($publicId)
        || !hash_equals($invitationPublicId, $publicId)
        || $generation === false
        || $generation < 0
        || $generation !== $expectedGeneration
        || $expiresAt === ''
    ) {
        throw new RuntimeException('Website invitation validation returned mismatched evidence.');
    }

    try {
        $expiry = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
    } catch (Throwable) {
        throw new RuntimeException('Website invitation validation returned an invalid expiration.');
    }
    if ($expiry <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
        return null;
    }

    return [
        'invitation_public_id' => $publicId,
        'generation' => $generation,
        'expires_at' => $expiry->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
    ];
}

/**
 * Called by Website only after Website validates the raw invitation token.
 * Browser binding is deliberately created and retained inside Auth.
 *
 * @return array{public_id:string,next_path:string,destination_key:string,expires_at:string}
 */
function fc_auth_crew_invitation_continuation_issue(
    PDO $pdo,
    string $invitationPublicId,
    int $invitationGeneration,
    DateTimeInterface|string $invitationExpiresAt
): array {
    if ($pdo->inTransaction()) {
        throw new LogicException('Invitation continuation issuance requires no active caller transaction.');
    }

    $invitationPublicId = trim($invitationPublicId);
    if (!fc_public_id_is_valid($invitationPublicId)) {
        throw new InvalidArgumentException('Invitation public ID is invalid.');
    }
    if ($invitationGeneration < 0) {
        throw new InvalidArgumentException('Invitation generation must not be negative.');
    }

    try {
        $invitationExpiry = $invitationExpiresAt instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($invitationExpiresAt)
            : new DateTimeImmutable(trim($invitationExpiresAt), new DateTimeZone('UTC'));
    } catch (Throwable) {
        throw new InvalidArgumentException('Invitation expiration is invalid.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $invitationExpiry = $invitationExpiry->setTimezone(new DateTimeZone('UTC'));
    if ($invitationExpiry <= $now) {
        throw new DomainException('Invitation is no longer valid.');
    }
    $maximumExpiry = $now->modify('+' . FC_AUTH_CREW_INVITATION_MAX_TTL_SECONDS . ' seconds');
    $continuationExpiry = $invitationExpiry < $maximumExpiry ? $invitationExpiry : $maximumExpiry;

    $publicId = fc_new_public_id();
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'INSERT INTO auth_invitation_continuations ( ' .
            ' public_id, purpose, invitation_public_id, invitation_generation, ' .
            ' browser_session_binding_hash, expires_at ' .
            ') VALUES ( ' .
            ' :public_id, :purpose, :invitation_public_id, :invitation_generation, ' .
            ' :browser_hash, :expires_at ' .
            ')'
        );
        $statement->execute([
            ':public_id' => $publicId,
            ':purpose' => FC_AUTH_CREW_INVITATION_PURPOSE,
            ':invitation_public_id' => $invitationPublicId,
            ':invitation_generation' => $invitationGeneration,
            ':browser_hash' => fc_secret_evidence_hash(fc_auth_browser_binding()),
            ':expires_at' => $continuationExpiry->format('Y-m-d H:i:s.u'),
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    // PHP session mutation occurs only after the continuation row is committed.
    $_SESSION[FC_AUTH_CREW_INVITATION_SESSION_KEY] = $publicId;

    return [
        'public_id' => $publicId,
        'next_path' => '/login.php',
        'destination_key' => FC_AUTH_CREW_INVITATION_DESTINATION,
        'expires_at' => $continuationExpiry->format('Y-m-d H:i:s.u'),
    ];
}

function fc_auth_crew_invitation_continuation_session_public_id(): ?string
{
    $value = $_SESSION[FC_AUTH_CREW_INVITATION_SESSION_KEY] ?? null;
    if (!is_string($value) || !fc_public_id_is_valid($value)) {
        unset($_SESSION[FC_AUTH_CREW_INVITATION_SESSION_KEY]);
        return null;
    }

    return $value;
}

function fc_auth_crew_invitation_continuation_clear_session(?string $expectedPublicId = null): void
{
    $current = $_SESSION[FC_AUTH_CREW_INVITATION_SESSION_KEY] ?? null;
    if ($expectedPublicId === null || (is_string($current) && hash_equals($expectedPublicId, $current))) {
        unset($_SESSION[FC_AUTH_CREW_INVITATION_SESSION_KEY]);
    }
}

/** @return array<string,mixed>|null */
function fc_auth_crew_invitation_continuation_find_for_browser(
    PDO $pdo,
    string $publicId,
    array $statuses,
    bool $forUpdate = false
): ?array {
    if (!fc_public_id_is_valid($publicId) || $statuses === []) {
        return null;
    }
    $allowedStatuses = ['ISSUED', 'LOGIN_BOUND', 'AUTHENTICATED', 'CONSUMED'];
    foreach ($statuses as $status) {
        if (!in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException('Invalid invitation continuation status.');
        }
    }
    if ($forUpdate && !$pdo->inTransaction()) {
        throw new LogicException('Locking an invitation continuation requires an active database transaction.');
    }

    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $sql =
        'SELECT * FROM auth_invitation_continuations ' .
        'WHERE public_id = ? ' .
        '  AND purpose = ? ' .
        '  AND browser_session_binding_hash = ? ' .
        '  AND continuation_status IN (' . $placeholders . ') ' .
        '  AND expires_at > CURRENT_TIMESTAMP(6) ' .
        'LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $parameters = [
        $publicId,
        FC_AUTH_CREW_INVITATION_PURPOSE,
        fc_secret_evidence_hash(fc_auth_browser_binding()),
        ...$statuses,
    ];
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/** @return array<string,mixed>|null */
function fc_auth_crew_invitation_continuation_pending_for_login(PDO $pdo): ?array
{
    $publicId = fc_auth_crew_invitation_continuation_session_public_id();
    if ($publicId === null) {
        return null;
    }

    $row = fc_auth_crew_invitation_continuation_find_for_browser($pdo, $publicId, ['ISSUED']);
    if ($row === null && !$pdo->inTransaction()) {
        fc_auth_crew_invitation_continuation_clear_session($publicId);
    }

    return $row;
}

function fc_auth_crew_invitation_continuation_bind_login_transaction(
    PDO $pdo,
    string $continuationPublicId,
    int $authTransactionId
): void {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Binding an invitation continuation requires an active database transaction.');
    }

    $row = fc_auth_crew_invitation_continuation_find_for_browser(
        $pdo,
        $continuationPublicId,
        ['ISSUED'],
        true
    );
    if ($row === null) {
        throw new DomainException('invitation_continuation_invalid');
    }

    $statement = $pdo->prepare(
        'UPDATE auth_invitation_continuations ' .
        'SET auth_transaction_id = :transaction_id, continuation_status = \'LOGIN_BOUND\', ' .
        '    login_bound_at = CURRENT_TIMESTAMP(6) ' .
        'WHERE id = :id AND continuation_status = \'ISSUED\' AND auth_transaction_id IS NULL'
    );
    $statement->execute([
        ':transaction_id' => $authTransactionId,
        ':id' => (int) $row['id'],
    ]);
    if ($statement->rowCount() !== 1) {
        throw new DomainException('invitation_continuation_already_bound');
    }
}

/** @return array<string,mixed>|null */
function fc_auth_crew_invitation_continuation_for_transaction(
    PDO $pdo,
    int $authTransactionId,
    bool $forUpdate = false
): ?array {
    if ($forUpdate && !$pdo->inTransaction()) {
        throw new LogicException('Locking an invitation continuation requires an active database transaction.');
    }
    $sql =
        'SELECT * FROM auth_invitation_continuations ' .
        'WHERE auth_transaction_id = :transaction_id ' .
        '  AND purpose = :purpose ' .
        '  AND browser_session_binding_hash = :browser_hash ' .
        '  AND continuation_status = \'LOGIN_BOUND\' ' .
        '  AND expires_at > CURRENT_TIMESTAMP(6) ' .
        'LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $statement = $pdo->prepare($sql);
    $statement->execute([
        ':transaction_id' => $authTransactionId,
        ':purpose' => FC_AUTH_CREW_INVITATION_PURPOSE,
        ':browser_hash' => fc_secret_evidence_hash(fc_auth_browser_binding()),
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

function fc_auth_crew_invitation_continuation_mark_authenticated(
    PDO $pdo,
    int $continuationId,
    int $userId,
    int $sessionRecordId,
    bool $admittedNewAccount
): void {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Completing an invitation continuation requires an active database transaction.');
    }

    $statement = $pdo->prepare(
        'UPDATE auth_invitation_continuations ' .
        'SET authenticated_user_id = :user_id, authenticated_session_id = :session_id, ' .
        '    continuation_status = \'AUTHENTICATED\', authenticated_at = CURRENT_TIMESTAMP(6), ' .
        '    admission_consumed_at = CASE WHEN :admitted = 1 THEN CURRENT_TIMESTAMP(6) ELSE admission_consumed_at END ' .
        'WHERE id = :id AND continuation_status IN (\'ISSUED\',\'LOGIN_BOUND\') ' .
        '  AND authenticated_user_id IS NULL AND authenticated_session_id IS NULL ' .
        '  AND expires_at > CURRENT_TIMESTAMP(6)'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':session_id' => $sessionRecordId,
        ':admitted' => $admittedNewAccount ? 1 : 0,
        ':id' => $continuationId,
    ]);
    if ($statement->rowCount() !== 1) {
        throw new DomainException('invitation_continuation_already_used');
    }
}

/**
 * Atomically claims the one-new-account authority of a logical Website
 * invitation. The primary key on invitation_public_id is the final arbiter.
 */
function fc_auth_crew_invitation_admission_claim(
    PDO $pdo,
    int $continuationId,
    string $invitationPublicId
): void {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Invitation admission claim requires an active database transaction.');
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO auth_invitation_admission_claims ' .
            '    (invitation_public_id, continuation_id) ' .
            'VALUES (:invitation_public_id, :continuation_id)'
        );
        $statement->execute([
            ':invitation_public_id' => $invitationPublicId,
            ':continuation_id' => $continuationId,
        ]);
    } catch (PDOException $error) {
        $driverError = (int) ($error->errorInfo[1] ?? 0);
        if ((string) $error->getCode() === '23000' && $driverError === 1062) {
            throw new DomainException('prelaunch_invitation_admission_claimed');
        }
        throw $error;
    }
}

function fc_auth_crew_invitation_admission_complete(
    PDO $pdo,
    int $continuationId,
    int $admittedUserId
): void {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Completing an invitation admission claim requires an active database transaction.');
    }

    $statement = $pdo->prepare(
        'UPDATE auth_invitation_admission_claims ' .
        'SET admitted_user_id = :user_id, completed_at = CURRENT_TIMESTAMP(6) ' .
        'WHERE continuation_id = :continuation_id ' .
        '  AND admitted_user_id IS NULL AND completed_at IS NULL'
    );
    $statement->execute([
        ':user_id' => $admittedUserId,
        ':continuation_id' => $continuationId,
    ]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Invitation admission claim could not be completed.');
    }
}

/**
 * Completes a continuation for a user who already has a valid FitCrew session.
 *
 * @return array<string,mixed>
 */
function fc_auth_crew_invitation_continuation_bind_existing_session(
    PDO $pdo,
    array $currentUser
): array {
    $publicId = fc_auth_crew_invitation_continuation_session_public_id();
    if ($publicId === null) {
        throw new DomainException('invitation_continuation_invalid');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $continuation = fc_auth_crew_invitation_continuation_find_for_browser(
            $pdo,
            $publicId,
            ['ISSUED', 'AUTHENTICATED'],
            true
        );
        if ($continuation === null) {
            throw new DomainException('invitation_continuation_invalid');
        }

        if ((string) $continuation['continuation_status'] === 'AUTHENTICATED') {
            if (
                (int) $continuation['authenticated_user_id'] !== (int) $currentUser['user_id']
                || (int) $continuation['authenticated_session_id'] !== (int) $currentUser['session_record_id']
            ) {
                throw new DomainException('invitation_continuation_invalid');
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'destination' => fc_auth_destination_path(FC_AUTH_CREW_INVITATION_DESTINATION),
            ];
        }

        $snapshot = fc_auth_crew_invitation_product_snapshot(
            $pdo,
            (string) $continuation['invitation_public_id'],
            (int) $continuation['invitation_generation'],
            false
        );
        if ($snapshot === null) {
            throw new DomainException('invitation_continuation_product_invalid');
        }

        fc_auth_crew_invitation_continuation_mark_authenticated(
            $pdo,
            (int) $continuation['id'],
            (int) $currentUser['user_id'],
            (int) $currentUser['session_record_id'],
            false
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return [
        'destination' => fc_auth_destination_path(FC_AUTH_CREW_INVITATION_DESTINATION),
    ];
}

/**
 * Internal current-continuation lookup. Auth verifies the current canonical
 * user/session and browser binding; callers outside Auth never receive this row.
 *
 * @return array<string,mixed>|null
 */
function fc_auth_crew_invitation_continuation_current_internal(PDO $pdo, bool $forUpdate): ?array
{
    $publicId = fc_auth_crew_invitation_continuation_session_public_id();
    $currentUser = fc_current_user();
    if ($publicId === null || $currentUser === null) {
        return null;
    }
    if ($forUpdate && !$pdo->inTransaction()) {
        throw new LogicException('Locking an authenticated continuation requires an active database transaction.');
    }

    $sql =
        'SELECT public_id, invitation_public_id, invitation_generation, expires_at, ' .
        '       authenticated_user_id, authenticated_session_id ' .
        'FROM auth_invitation_continuations ' .
        'WHERE public_id = :public_id ' .
        '  AND purpose = :purpose ' .
        '  AND browser_session_binding_hash = :browser_hash ' .
        '  AND authenticated_user_id = :user_id ' .
        '  AND authenticated_session_id = :session_id ' .
        '  AND continuation_status = \'AUTHENTICATED\' ' .
        '  AND consumed_at IS NULL ' .
        '  AND expires_at > CURRENT_TIMESTAMP(6) ' .
        'LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $statement = $pdo->prepare($sql);
    $statement->execute([
        ':public_id' => $publicId,
        ':purpose' => FC_AUTH_CREW_INVITATION_PURPOSE,
        ':browser_hash' => fc_secret_evidence_hash(fc_auth_browser_binding()),
        ':user_id' => (int) $currentUser['user_id'],
        ':session_id' => (int) $currentUser['session_record_id'],
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        // Only committed database truth may clear nontransactional PHP session
        // state. Never clear while the caller may still roll back SQL changes.
        if (!$pdo->inTransaction()) {
            fc_auth_crew_invitation_continuation_clear_session($publicId);
        }
        return null;
    }

    return $row;
}

/**
 * Minimal Website read interface for the authenticated continuation in the
 * current browser/session.
 *
 * @return array{invitation_public_id:string,generation:int,expires_at:string}|null
 */
function fc_auth_crew_invitation_continuation_current(PDO $pdo): ?array
{
    $row = fc_auth_crew_invitation_continuation_current_internal($pdo, false);
    if ($row === null) {
        return null;
    }

    return [
        'invitation_public_id' => (string) $row['invitation_public_id'],
        'generation' => (int) $row['invitation_generation'],
        'expires_at' => (string) $row['expires_at'],
    ];
}

/**
 * Website calls this inside its invitation-acceptance transaction after its
 * final locked validation and membership decision.
 */
function fc_auth_crew_invitation_continuation_consume(
    PDO $pdo,
    string $invitationPublicId,
    int $invitationGeneration
): bool {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Consuming an invitation continuation requires an active database transaction.');
    }
    $current = fc_auth_crew_invitation_continuation_current_internal($pdo, true);
    if (
        $current === null
        || !hash_equals((string) $current['invitation_public_id'], $invitationPublicId)
        || (int) $current['invitation_generation'] !== $invitationGeneration
    ) {
        return false;
    }

    $statement = $pdo->prepare(
        'UPDATE auth_invitation_continuations ' .
        'SET continuation_status = \'CONSUMED\', consumed_at = CURRENT_TIMESTAMP(6) ' .
        'WHERE public_id = :public_id AND continuation_status = \'AUTHENTICATED\' AND consumed_at IS NULL'
    );
    $statement->execute([':public_id' => (string) $current['public_id']]);

    // The caller owns this SQL transaction. PHP session state is deliberately
    // left untouched until a later request observes committed terminal state.
    return $statement->rowCount() === 1;
}
