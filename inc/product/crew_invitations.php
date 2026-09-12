<?php

declare(strict_types=1);

const FC_CREW_INVITATION_TTL_SECONDS = 604800; // 7 days

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

/** @return array<string,mixed> */
function fc_crew_invitation_create(PDO $pdo, int $actorUserId, int $crewId, string $email): array
{
    $email = fc_crew_invitation_email($email);
    $crew = fc_crew_require_owner($pdo, $actorUserId, $crewId);

    $user = fc_user_find_by_id($pdo, $actorUserId, true);
    if ($user === null) {
        throw new DomainException('Inviter is unavailable.');
    }

    $token = fc_crew_invitation_token();
    $hash = fc_crew_invitation_token_hash($token);
    $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . FC_CREW_INVITATION_TTL_SECONDS . ' seconds')
        ->format('Y-m-d H:i:s.u');

    $result = fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $crewId, $email, $hash, $expires): array {
        fc_family_lock_crew($pdo, $crewId);
        fc_crew_require_owner($pdo, $actorUserId, $crewId);

        $existing = $pdo->prepare(
            'SELECT id FROM crew_invitations WHERE crew_id=:c AND invited_email=:e AND invitation_status=\'PENDING\' ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $existing->execute([':c' => $crewId, ':e' => $email]);
        if ($existing->fetchColumn() !== false) {
            throw new DomainException('A pending invitation already exists for that email. Resend or cancel it instead.');
        }

        $publicId = fc_new_public_id();
        $insert = $pdo->prepare(
            'INSERT INTO crew_invitations (public_id,crew_id,invited_email,invited_by_user_id,token_hash,expires_at) ' .
            'VALUES (:p,:c,:e,:u,:h,:x)'
        );
        $insert->execute([':p'=>$publicId, ':c'=>$crewId, ':e'=>$email, ':u'=>$actorUserId, ':h'=>$hash, ':x'=>$expires]);
        return ['id'=>(int)$pdo->lastInsertId(), 'public_id'=>$publicId];
    });

    return [
        'id' => $result['id'],
        'public_id' => $result['public_id'],
        'token' => $token,
        'email' => $email,
        'crew_name' => (string) $crew['display_name'],
        'inviter_name' => (string) ($user['display_name'] ?? 'FitCrew'),
        'expires_at' => $expires,
    ];
}

/** @return list<array<string,mixed>> */
function fc_crew_invitations_pending(PDO $pdo, int $actorUserId, int $crewId): array
{
    fc_crew_require_owner($pdo, $actorUserId, $crewId);
    $query = $pdo->prepare(
        'SELECT public_id, invited_email, sent_at, resend_count, expires_at ' .
        'FROM crew_invitations WHERE crew_id=:c AND invitation_status=\'PENDING\' ORDER BY id DESC'
    );
    $query->execute([':c'=>$crewId]);
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

    $row = fc_product_atomic($pdo, function () use ($pdo,$actorUserId,$crewId,$publicId,$hash,$expires): array {
        fc_family_lock_crew($pdo,$crewId);
        $row = fc_crew_invitation_require_owner($pdo,$actorUserId,$crewId,$publicId);
        if ((string)$row['invitation_status'] !== 'PENDING') throw new DomainException('Only a pending invitation can be resent.');
        $update=$pdo->prepare('UPDATE crew_invitations SET token_hash=:h,expires_at=:x,sent_at=CURRENT_TIMESTAMP(6),resend_count=resend_count+1 WHERE id=:id');
        $update->execute([':h'=>$hash,':x'=>$expires,':id'=>(int)$row['id']]);
        return $row;
    });

    return [
        'public_id'=>(string)$row['public_id'], 'token'=>$token, 'email'=>(string)$row['invited_email'],
        'crew_name'=>(string)$crew['display_name'], 'inviter_name'=>(string)($user['display_name'] ?? 'FitCrew'), 'expires_at'=>$expires,
    ];
}

function fc_crew_invitation_cancel(PDO $pdo, int $actorUserId, int $crewId, string $publicId): void
{
    fc_product_atomic($pdo, function () use ($pdo,$actorUserId,$crewId,$publicId): void {
        fc_family_lock_crew($pdo,$crewId);
        $row=fc_crew_invitation_require_owner($pdo,$actorUserId,$crewId,$publicId);
        if ((string)$row['invitation_status'] === 'CANCELLED') return;
        if ((string)$row['invitation_status'] !== 'PENDING') throw new DomainException('That invitation is no longer pending.');
        $pdo->prepare('UPDATE crew_invitations SET invitation_status=\'CANCELLED\',cancelled_at=CURRENT_TIMESTAMP(6) WHERE id=:id')->execute([':id'=>(int)$row['id']]);
    });
}

/** @return array<string,mixed>|null */
function fc_crew_invitation_find_token(PDO $pdo, string $token): ?array
{
    $hash = fc_crew_invitation_token_hash($token);
    $query = $pdo->prepare(
        'SELECT i.*, c.display_name AS crew_name, u.display_name AS inviter_name ' .
        'FROM crew_invitations i JOIN crews c ON c.id=i.crew_id JOIN users u ON u.id=i.invited_by_user_id ' .
        'WHERE i.token_hash=:h LIMIT 1'
    );
    $query->execute([':h'=>$hash]);
    $row=$query->fetch(PDO::FETCH_ASSOC);
    if ($row === false) return null;
    if ((string)$row['invitation_status'] === 'PENDING' && strtotime((string)$row['expires_at']) < time()) {
        $pdo->prepare('UPDATE crew_invitations SET invitation_status=\'EXPIRED\' WHERE id=:id AND invitation_status=\'PENDING\'')->execute([':id'=>(int)$row['id']]);
        $row['invitation_status']='EXPIRED';
    }
    return $row;
}

function fc_crew_invitation_accept(PDO $pdo, int $actorUserId, string $token): int
{
    return fc_product_atomic($pdo, function () use ($pdo,$actorUserId,$token): int {
        $hash=fc_crew_invitation_token_hash($token);
        $query=$pdo->prepare('SELECT * FROM crew_invitations WHERE token_hash=:h LIMIT 1 FOR UPDATE');
        $query->execute([':h'=>$hash]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new DomainException('Invitation is unavailable.');
        if ((string)$row['invitation_status'] !== 'PENDING') throw new DomainException('This invitation is no longer pending.');
        if (strtotime((string)$row['expires_at']) < time()) {
            $pdo->prepare('UPDATE crew_invitations SET invitation_status=\'EXPIRED\' WHERE id=:id')->execute([':id'=>(int)$row['id']]);
            throw new DomainException('This invitation expired. Ask the Crew Owner to resend it.');
        }
        fc_family_lock_crew($pdo,(int)$row['crew_id']);
        if (fc_user_find_by_id($pdo,$actorUserId,true) === null) throw new DomainException('Signed-in FitCrew user is unavailable.');
        $membership=$pdo->prepare('SELECT id,role_code FROM crew_memberships WHERE crew_id=:c AND user_id=:u LIMIT 1 FOR UPDATE');
        $membership->execute([':c'=>(int)$row['crew_id'],':u'=>$actorUserId]);
        $existing=$membership->fetch(PDO::FETCH_ASSOC);
        if ($existing === false) {
            $pdo->prepare('INSERT INTO crew_memberships (crew_id,user_id,role_code,membership_status) VALUES (:c,:u,\'MEMBER\',\'ACTIVE\')')->execute([':c'=>(int)$row['crew_id'],':u'=>$actorUserId]);
        } elseif ((string)$existing['role_code'] !== 'OWNER') {
            $pdo->prepare('UPDATE crew_memberships SET membership_status=\'ACTIVE\',left_at=NULL,removed_at=NULL,joined_at=CURRENT_TIMESTAMP(6) WHERE id=:id')->execute([':id'=>(int)$existing['id']]);
        }
        $pdo->prepare('UPDATE crew_invitations SET invitation_status=\'ACCEPTED\',accepted_by_user_id=:u,accepted_at=CURRENT_TIMESTAMP(6) WHERE id=:id')->execute([':u'=>$actorUserId,':id'=>(int)$row['id']]);
        return (int)$row['crew_id'];
    });
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
        $acceptUrl
    );
    return fc_mail_send($message);
}
