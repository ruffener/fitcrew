<?php

declare(strict_types=1);

const FC_CREW_INVITATION_TTL_SECONDS = 604800; // 7 days

const FC_CREW_INVITATION_RATE_ISSUE_NAMESPACE = 'crew_invitation.issue';
const FC_CREW_INVITATION_RATE_RESEND_NAMESPACE = 'crew_invitation.resend';
const FC_CREW_INVITATION_RATE_INVALID_RAW_NAMESPACE = 'crew_invitation.invalid_raw';

function fc_crew_invitation_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    return $email;
}

function fc_crew_invitation_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function fc_crew_invitation_token_hash(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        throw new InvalidArgumentException('Invitation token is required.');
    }
    return hash('sha256', $token);
}

/** @param array{allowed:bool,remaining:int,retry_after_seconds:int} $result */
function fc_crew_invitation_rate_limit_require(array $result, string $message): void
{
    if (($result['allowed'] ?? false) === true) {
        return;
    }
    $retry = max(1, (int) ($result['retry_after_seconds'] ?? 1));
    throw new DomainException($message . ' Try again in about ' . $retry . ' seconds.');
}

function fc_crew_invitation_rate_limit_issue(PDO $pdo, int $ownerUserId): void
{
    $result = fc_rate_limit_consume(
        $pdo,
        FC_CREW_INVITATION_RATE_ISSUE_NAMESPACE,
        'owner:' . $ownerUserId,
        10,
        900
    );
    fc_crew_invitation_rate_limit_require($result, 'Too many Crew invitations were started.');
}

function fc_crew_invitation_rate_limit_resend(PDO $pdo, int $ownerUserId, string $invitationPublicId): void
{
    $result = fc_rate_limit_consume(
        $pdo,
        FC_CREW_INVITATION_RATE_RESEND_NAMESPACE,
        'owner:' . $ownerUserId . '|invitation:' . trim($invitationPublicId),
        5,
        900
    );
    fc_crew_invitation_rate_limit_require($result, 'That invitation has been resent too many times.');
}

function fc_crew_invitation_client_rate_subject(): string
{
    $network = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    if ($network === '') {
        $network = 'unknown';
    }
    $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return 'network:' . $network . '|agent:' . hash('sha256', $agent);
}

function fc_crew_invitation_rate_limit_invalid_raw(PDO $pdo, string $clientSubject): void
{
    $result = fc_rate_limit_consume(
        $pdo,
        FC_CREW_INVITATION_RATE_INVALID_RAW_NAMESPACE,
        $clientSubject,
        20,
        900
    );
    fc_crew_invitation_rate_limit_require($result, 'Too many invalid invitation attempts were received.');
}

/** @return array<string,mixed> */
function fc_crew_invitation_create(PDO $pdo, int $actorUserId, int $crewId, int $challengeId, string $email): array
{
    $email = fc_crew_invitation_email($email);
    $crew = fc_crew_require_owner($pdo, $actorUserId, $crewId);
    $user = fc_user_find_by_id($pdo, $actorUserId, true);
    if ($user === null) throw new DomainException('Inviter is unavailable.');

    $current = fc_crew_current_challenge($pdo, $crewId);
    if ($current === null || (int) $current['id'] !== $challengeId) {
        throw new DomainException('Invitations can only target this Crew’s current Challenge.');
    }
    if (fc_challenge_rule_current_published($pdo, $challengeId) === null) {
        throw new DomainException('Publish the current Challenge Rules before inviting participants.');
    }

    $token = fc_crew_invitation_token();
    $hash = fc_crew_invitation_token_hash($token);
    $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . FC_CREW_INVITATION_TTL_SECONDS . ' seconds')
        ->format('Y-m-d H:i:s.u');

    $result = fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $crewId, $challengeId, $email, $hash, $expires): array {
        fc_family_lock_crew($pdo, $crewId);
        fc_crew_require_owner($pdo, $actorUserId, $crewId);
        $current = fc_crew_current_challenge($pdo, $crewId, true);
        if ($current === null || (int) $current['id'] !== $challengeId) {
            throw new DomainException('The Crew’s current Challenge changed before the invitation was created.');
        }
        if (fc_challenge_rule_current_published($pdo, $challengeId) === null) {
            throw new DomainException('Publish the current Challenge Rules before inviting participants.');
        }

        $existing = $pdo->prepare(
            'SELECT id FROM crew_invitations WHERE crew_id=:c AND challenge_id=:challenge AND invited_email=:e ' .
            'AND invitation_status=\'PENDING\' ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $existing->execute([':c' => $crewId, ':challenge' => $challengeId, ':e' => $email]);
        if ($existing->fetchColumn() !== false) {
            throw new DomainException('A pending invitation already exists for that email and Challenge. Resend or cancel it instead.');
        }

        $publicId = fc_new_public_id();
        $insert = $pdo->prepare(
            'INSERT INTO crew_invitations ' .
            '(public_id,crew_id,challenge_id,invited_email,invited_by_user_id,token_hash,expires_at,transport_status,sent_at) ' .
            'VALUES (:p,:c,:challenge,:e,:u,:h,:x,\'PENDING_SEND\',NULL)'
        );
        $insert->execute([
            ':p' => $publicId, ':c' => $crewId, ':challenge' => $challengeId, ':e' => $email,
            ':u' => $actorUserId, ':h' => $hash, ':x' => $expires,
        ]);
        return ['id' => (int) $pdo->lastInsertId(), 'public_id' => $publicId];
    });

    return [
        'id' => $result['id'],
        'public_id' => $result['public_id'],
        'generation' => 0,
        'token' => $token,
        'email' => $email,
        'crew_name' => (string) $crew['display_name'],
        'challenge_name' => (string) $current['display_name'],
        'inviter_name' => (string) ($user['display_name'] ?? 'FitCrew'),
        'account_presence' => fc_auth_account_presence_for_email($pdo, $email),
        'expires_at' => $expires,
    ];
}

/** @return list<array<string,mixed>> */
function fc_crew_invitations_pending(PDO $pdo, int $actorUserId, int $crewId): array
{
    fc_crew_require_owner($pdo, $actorUserId, $crewId);
    $query = $pdo->prepare(
        'SELECT i.public_id,i.challenge_id,i.invited_email,i.sent_at,i.resend_count,i.expires_at,' .
        'i.transport_status,i.transport_driver,i.transport_message_id,i.transport_attempted_at,' .
        'c.public_id AS challenge_public_id,c.display_name AS challenge_name ' .
        'FROM crew_invitations i LEFT JOIN challenges c ON c.id=i.challenge_id ' .
        'WHERE i.crew_id=:c AND i.invitation_status=\'PENDING\' ORDER BY i.id DESC'
    );
    $query->execute([':c' => $crewId]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string,mixed> */
function fc_crew_invitation_require_owner(PDO $pdo, int $actorUserId, int $crewId, string $publicId): array
{
    fc_crew_require_owner($pdo, $actorUserId, $crewId);
    $query = $pdo->prepare('SELECT * FROM crew_invitations WHERE public_id=:p AND crew_id=:c LIMIT 1');
    $query->execute([':p'=>trim($publicId), ':c'=>$crewId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new DomainException('Invitation is unavailable.');
    }
    return $row;
}

/** @return array<string,mixed> */
function fc_crew_invitation_resend(PDO $pdo, int $actorUserId, int $crewId, string $publicId): array
{
    $crew = fc_crew_require_owner($pdo, $actorUserId, $crewId);
    $user = fc_user_find_by_id($pdo, $actorUserId, true);
    if ($user === null) throw new DomainException('Inviter is unavailable.');

    $token = fc_crew_invitation_token();
    $hash = fc_crew_invitation_token_hash($token);
    $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . FC_CREW_INVITATION_TTL_SECONDS . ' seconds')
        ->format('Y-m-d H:i:s.u');

    $row = fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $crewId, $publicId, $hash, $expires): array {
        fc_family_lock_crew($pdo, $crewId);
        $row = fc_crew_invitation_require_owner($pdo, $actorUserId, $crewId, $publicId);
        if ((string) $row['invitation_status'] !== 'PENDING') throw new DomainException('Only a pending invitation can be resent.');
        if ($row['challenge_id'] === null) {
            throw new DomainException('This is a legacy Crew-only invitation. Cancel it and create a new Challenge invitation.');
        }
        $current = fc_crew_current_challenge($pdo, $crewId, true);
        if ($current === null || (int) $current['id'] !== (int) $row['challenge_id']) {
            throw new DomainException('That invitation no longer targets this Crew’s current Challenge.');
        }
        $pdo->prepare(
            'UPDATE challenge_invitation_acceptance_intents SET intent_status=\'INVALIDATED\' ' .
            'WHERE invitation_id=:invitation_id AND intent_status=\'PENDING\''
        )->execute([':invitation_id' => (int) $row['id']]);

        $update = $pdo->prepare(
            'UPDATE crew_invitations SET token_hash=:h,expires_at=:x,resend_count=resend_count+1,' .
            'transport_status=\'PENDING_SEND\',transport_driver=NULL,transport_message_id=NULL,' .
            'transport_attempted_at=NULL,sent_at=NULL WHERE id=:id'
        );
        $update->execute([':h' => $hash, ':x' => $expires, ':id' => (int) $row['id']]);
        return $row;
    });

    $challenge = fc_crew_current_challenge($pdo, $crewId);
    return [
        'public_id' => (string) $row['public_id'],
        'generation' => (int) $row['resend_count'] + 1,
        'token' => $token,
        'email' => (string) $row['invited_email'],
        'crew_name' => (string) $crew['display_name'],
        'challenge_name' => (string) ($challenge['display_name'] ?? 'current Challenge'),
        'inviter_name' => (string) ($user['display_name'] ?? 'FitCrew'),
        'account_presence' => fc_auth_account_presence_for_email($pdo, (string) $row['invited_email']),
        'expires_at' => $expires,
    ];
}

/**
 * Read-only invitation snapshot for Auth admission proof.
 *
 * @return array{invitation_public_id:string,generation:int,expires_at:string}|null
 */
function fc_crew_invitation_auth_snapshot(
    PDO $pdo,
    string $invitationPublicId,
    int $expectedGeneration,
    bool $lockForAdmission = false
): ?array {
    $invitationPublicId = trim($invitationPublicId);
    if ($invitationPublicId === '' || $expectedGeneration < 0) {
        return null;
    }

    if ($lockForAdmission && !$pdo->inTransaction()) {
        throw new DomainException('Invitation admission lock requires an active database transaction.');
    }

    $sql =
        'SELECT public_id, resend_count, expires_at ' .
        'FROM crew_invitations ' .
        'WHERE public_id=:p ' .
        'AND resend_count=:g ' .
        'AND invitation_status=\'PENDING\' ' .
        'AND expires_at > CURRENT_TIMESTAMP(6) ' .
        'AND accepted_by_user_id IS NULL ' .
        'AND accepted_at IS NULL ' .
        'AND cancelled_at IS NULL ' .
        'LIMIT 1';

    if ($lockForAdmission) {
        $sql .= ' FOR UPDATE';
    }

    $query = $pdo->prepare($sql);
    $query->execute([':p' => $invitationPublicId, ':g' => $expectedGeneration]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }

    return [
        'invitation_public_id' => (string) $row['public_id'],
        'generation' => (int) $row['resend_count'],
        'expires_at' => (string) $row['expires_at'],
    ];
}

function fc_crew_invitation_cancel(PDO $pdo, int $actorUserId, int $crewId, string $publicId): void
{
    fc_product_atomic($pdo, function () use ($pdo,$actorUserId,$crewId,$publicId): void {
        fc_family_lock_crew($pdo,$crewId);
        $row=fc_crew_invitation_require_owner($pdo,$actorUserId,$crewId,$publicId);
        if ((string)$row['invitation_status'] === 'CANCELLED') {
            return;
        }
        if ((string)$row['invitation_status'] !== 'PENDING') {
            throw new DomainException('That invitation is no longer pending.');
        }
        $pdo->prepare(
            'UPDATE challenge_invitation_acceptance_intents SET intent_status=\'INVALIDATED\' ' .
            'WHERE invitation_id=:invitation_id AND intent_status=\'PENDING\''
        )->execute([':invitation_id' => (int) $row['id']]);
        $pdo->prepare(
            'UPDATE crew_invitations SET invitation_status=\'CANCELLED\',cancelled_at=CURRENT_TIMESTAMP(6) WHERE id=:id'
        )->execute([':id'=>(int)$row['id']]);
    });
}

/** @return array<string,mixed>|null */
function fc_crew_invitation_find_token(PDO $pdo, string $token): ?array
{
    $hash = fc_crew_invitation_token_hash($token);
    $query = $pdo->prepare(
        'SELECT i.*,c.display_name AS crew_name,u.display_name AS inviter_name,ch.display_name AS challenge_name ' .
        'FROM crew_invitations i JOIN crews c ON c.id=i.crew_id JOIN users u ON u.id=i.invited_by_user_id ' .
        'LEFT JOIN challenges ch ON ch.id=i.challenge_id ' .
        'WHERE i.token_hash=:h LIMIT 1'
    );
    $query->execute([':h' => $hash]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if ($row === false) return null;
    if ((string) $row['invitation_status'] === 'PENDING' && strtotime((string) $row['expires_at']) < time()) {
        $pdo->prepare('UPDATE crew_invitations SET invitation_status=\'EXPIRED\' WHERE id=:id AND invitation_status=\'PENDING\'')
            ->execute([':id' => (int) $row['id']]);
        $row['invitation_status'] = 'EXPIRED';
    }
    return $row;
}

/** @param array<string,mixed> $invitation
 *  @return array{accepted:bool,driver:string,message_id:?string}
 */
function fc_crew_invitation_send_message(array $invitation): array
{
    $baseUrl = rtrim((string) fc_config()['url'], '/');
    $acceptUrl = $baseUrl . '/crew-invite.php?token=' . rawurlencode((string) $invitation['token']);
    $message = fc_mail_crew_invitation_message(
        (string) $invitation['email'],
        (string) $invitation['inviter_name'],
        (string) $invitation['crew_name'],
        (string) ($invitation['challenge_name'] ?? 'FitCrew Challenge'),
        (string) ($invitation['account_presence'] ?? 'UNKNOWN'),
        $acceptUrl
    );
    return fc_mail_send($message);
}

/**
 * Attempts the current-generation invitation transport and records truthful
 * configured-transport acceptance/failure. TRANSPORT_ACCEPTED never means
 * mailbox delivery.
 *
 * @param array<string,mixed> $invitation
 * @param null|callable(array<string,mixed>):array{accepted:bool,driver:string,message_id:?string} $sender
 * @return array{accepted:bool,driver:string,message_id:?string}
 */
function fc_crew_invitation_deliver(PDO $pdo, array $invitation, ?callable $sender = null): array
{
    $publicId=trim((string)($invitation['public_id'] ?? ''));
    $generation=(int)($invitation['generation'] ?? -1);
    if ($publicId === '' || $generation < 0) {
        throw new InvalidArgumentException('Invitation delivery evidence is incomplete.');
    }

    $driver=(string)(fc_mail_config()['driver'] ?? '');
    $attempt=$pdo->prepare(
        'UPDATE crew_invitations SET transport_status=\'PENDING_SEND\',transport_driver=:driver,' .
        'transport_message_id=NULL,transport_attempted_at=CURRENT_TIMESTAMP(6),sent_at=NULL ' .
        'WHERE public_id=:p AND resend_count=:g AND invitation_status=\'PENDING\''
    );
    $attempt->execute([':driver'=>$driver,':p'=>$publicId,':g'=>$generation]);
    if ($attempt->rowCount() !== 1) {
        throw new DomainException('Invitation changed before email transport could begin.');
    }

    $sender ??= static fn(array $message): array => fc_mail_send($message);
    $baseUrl = rtrim((string) fc_config()['url'], '/');
    $acceptUrl = $baseUrl . '/crew-invite.php?token=' . rawurlencode((string) $invitation['token']);
    $message = fc_mail_crew_invitation_message(
        (string) $invitation['email'],
        (string) $invitation['inviter_name'],
        (string) $invitation['crew_name'],
        (string) ($invitation['challenge_name'] ?? 'FitCrew Challenge'),
        (string) ($invitation['account_presence'] ?? 'UNKNOWN'),
        $acceptUrl
    );

    try {
        $delivery=$sender($message);
    } catch (Throwable $error) {
        $failed=$pdo->prepare(
            'UPDATE crew_invitations SET transport_status=\'TRANSPORT_FAILED\',transport_driver=:driver,' .
            'transport_message_id=NULL,sent_at=NULL WHERE public_id=:p AND resend_count=:g AND invitation_status=\'PENDING\''
        );
        $failed->execute([':driver'=>$driver,':p'=>$publicId,':g'=>$generation]);
        throw new DomainException('The invitation is pending, but email transport failed. Use Resend to try again.', 0, $error);
    }

    if (($delivery['accepted'] ?? false) !== true) {
        $failed=$pdo->prepare(
            'UPDATE crew_invitations SET transport_status=\'TRANSPORT_FAILED\',transport_driver=:driver,' .
            'transport_message_id=NULL,sent_at=NULL WHERE public_id=:p AND resend_count=:g AND invitation_status=\'PENDING\''
        );
        $failed->execute([':driver'=>$driver,':p'=>$publicId,':g'=>$generation]);
        throw new DomainException('The invitation is pending, but email transport did not accept the message.');
    }

    $accepted=$pdo->prepare(
        'UPDATE crew_invitations SET transport_status=\'TRANSPORT_ACCEPTED\',transport_driver=:driver,' .
        'transport_message_id=:message_id,sent_at=CURRENT_TIMESTAMP(6) ' .
        'WHERE public_id=:p AND resend_count=:g AND invitation_status=\'PENDING\''
    );
    $accepted->execute([
        ':driver'=>(string)($delivery['driver'] ?? $driver),
        ':message_id'=>isset($delivery['message_id']) && $delivery['message_id'] !== '' ? (string)$delivery['message_id'] : null,
        ':p'=>$publicId,
        ':g'=>$generation,
    ]);
    if ($accepted->rowCount() !== 1) {
        throw new DomainException('Invitation changed while email transport was being recorded. Use the latest invitation state.');
    }

    return [
        'accepted'=>true,
        'driver'=>(string)($delivery['driver'] ?? $driver),
        'message_id'=>isset($delivery['message_id']) && $delivery['message_id'] !== '' ? (string)$delivery['message_id'] : null,
    ];
}
