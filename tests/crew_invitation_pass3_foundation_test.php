<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/product/bootstrap.php';

function p3db_assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function p3db_denied(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (DomainException|InvalidArgumentException|LogicException) {
        return;
    }
    throw new RuntimeException($message . ': denial expected');
}

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "[BLOCKED] PDO MySQL driver is required for the PASS 3 DB proof.\n");
    exit(2);
}

$pdo = fc_db();
$pdo->beginTransaction();

try {
    $owner = fc_user_create($pdo, 'PASS3 Owner');
    $member = fc_user_create($pdo, 'PASS3 Member');
    $crew = fc_crew_create($pdo, (int)$owner['id'], 'PASS3 Crew');

    // Delivery truth: new current generation is pending until configured transport accepts it.
    $invite = fc_crew_invitation_create($pdo, (int)$owner['id'], (int)$crew['id'], 'alpha@example.com');
    $deliveryState = $pdo->prepare(
        'SELECT transport_status,transport_driver,transport_message_id,transport_attempted_at,sent_at,resend_count ' .
        'FROM crew_invitations WHERE public_id=:p'
    );
    $deliveryState->execute([':p'=>$invite['public_id']]);
    $row = $deliveryState->fetch(PDO::FETCH_ASSOC);
    p3db_assert($row !== false && $row['transport_status'] === 'PENDING_SEND' && $row['sent_at'] === null, 'New invitation must start PENDING_SEND without sent_at.');

    $acceptedDelivery = fc_crew_invitation_deliver(
        $pdo,
        $invite,
        static fn(array $message): array => ['accepted'=>true,'driver'=>'postmark','message_id'=>'pm-pass3-accepted']
    );
    p3db_assert($acceptedDelivery['accepted'] === true, 'Injected accepted transport failed.');
    $deliveryState->execute([':p'=>$invite['public_id']]);
    $row = $deliveryState->fetch(PDO::FETCH_ASSOC);
    p3db_assert(
        $row !== false
        && $row['transport_status'] === 'TRANSPORT_ACCEPTED'
        && $row['transport_driver'] === 'postmark'
        && $row['transport_message_id'] === 'pm-pass3-accepted'
        && $row['sent_at'] !== null,
        'Accepted transport truth was not recorded.'
    );

    $resend = fc_crew_invitation_resend($pdo, (int)$owner['id'], (int)$crew['id'], (string)$invite['public_id']);
    p3db_assert((int)$resend['generation'] === 1, 'Resend must advance invitation generation.');
    $deliveryState->execute([':p'=>$invite['public_id']]);
    $row = $deliveryState->fetch(PDO::FETCH_ASSOC);
    p3db_assert(
        $row !== false
        && (int)$row['resend_count'] === 1
        && $row['transport_status'] === 'PENDING_SEND'
        && $row['transport_message_id'] === null
        && $row['sent_at'] === null,
        'Resend must reset current-generation transport truth.'
    );

    p3db_denied(
        fn()=>fc_crew_invitation_deliver(
            $pdo,
            $resend,
            static function(array $message): array { throw new RuntimeException('synthetic transport failure'); }
        ),
        'Synthetic transport failure'
    );
    $deliveryState->execute([':p'=>$invite['public_id']]);
    $row = $deliveryState->fetch(PDO::FETCH_ASSOC);
    p3db_assert(
        $row !== false
        && $row['transport_status'] === 'TRANSPORT_FAILED'
        && $row['sent_at'] === null,
        'Failed transport must not look sent.'
    );

    // Stale generation cannot become membership.
    $consumeCalls = 0;
    p3db_denied(
        fn()=>fc_crew_invitation_accept_continuation(
            $pdo,
            (int)$member['id'],
            (string)$invite['public_id'],
            0,
            static function(PDO $db, string $publicId, int $generation) use (&$consumeCalls): bool {
                $consumeCalls++;
                return true;
            }
        ),
        'Stale generation acceptance'
    );
    p3db_assert($consumeCalls === 0, 'Stale Website validation must fail before Auth consume.');

    // Consume failure can be rolled back atomically by the Website-owned caller transaction.
    $pdo->exec('SAVEPOINT pass3_consume_failure');
    try {
        fc_crew_invitation_accept_continuation(
            $pdo,
            (int)$member['id'],
            (string)$invite['public_id'],
            1,
            static fn(PDO $db, string $publicId, int $generation): bool => false
        );
        throw new RuntimeException('Consume failure was accepted.');
    } catch (DomainException $expected) {
        $pdo->exec('ROLLBACK TO SAVEPOINT pass3_consume_failure');
        $pdo->exec('RELEASE SAVEPOINT pass3_consume_failure');
    }
    $membership = $pdo->prepare('SELECT COUNT(*) FROM crew_memberships WHERE crew_id=:c AND user_id=:u AND membership_status=\'ACTIVE\'');
    $membership->execute([':c'=>$crew['id'],':u'=>$member['id']]);
    p3db_assert((int)$membership->fetchColumn() === 0, 'Auth consume failure must roll back Website membership.');
    $invStatus = $pdo->prepare('SELECT invitation_status FROM crew_invitations WHERE public_id=:p');
    $invStatus->execute([':p'=>$invite['public_id']]);
    p3db_assert($invStatus->fetchColumn() === 'PENDING', 'Auth consume failure must roll back invitation acceptance.');

    // Cancellation / expiration during Auth flow fail before continuation consumption.
    $cancelled = fc_crew_invitation_create($pdo, (int)$owner['id'], (int)$crew['id'], 'cancelled@example.com');
    fc_crew_invitation_cancel($pdo, (int)$owner['id'], (int)$crew['id'], (string)$cancelled['public_id']);
    $consumeCalls = 0;
    p3db_denied(
        fn()=>fc_crew_invitation_accept_continuation(
            $pdo,
            (int)$member['id'],
            (string)$cancelled['public_id'],
            0,
            static function(PDO $db, string $publicId, int $generation) use (&$consumeCalls): bool {
                $consumeCalls++;
                return true;
            }
        ),
        'Cancelled invitation acceptance'
    );
    p3db_assert($consumeCalls === 0, 'Cancelled invitation must fail before continuation consume.');

    $expired = fc_crew_invitation_create($pdo, (int)$owner['id'], (int)$crew['id'], 'expired@example.com');
    $pdo->prepare('UPDATE crew_invitations SET expires_at=DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE public_id=:p')
        ->execute([':p'=>$expired['public_id']]);
    $consumeCalls = 0;
    p3db_denied(
        fn()=>fc_crew_invitation_accept_continuation(
            $pdo,
            (int)$member['id'],
            (string)$expired['public_id'],
            0,
            static function(PDO $db, string $publicId, int $generation) use (&$consumeCalls): bool {
                $consumeCalls++;
                return true;
            }
        ),
        'Expired invitation acceptance'
    );
    p3db_assert($consumeCalls === 0, 'Expired invitation must fail before continuation consume.');

    // Already-member / Owner acceptance must not duplicate or downgrade membership.
    $ownerInvite = fc_crew_invitation_create($pdo, (int)$owner['id'], (int)$crew['id'], 'owner-alt@example.com');
    $beforeCount = $pdo->prepare('SELECT COUNT(*) FROM crew_memberships WHERE crew_id=:c AND user_id=:u');
    $beforeCount->execute([':c'=>$crew['id'],':u'=>$owner['id']]);
    $countBefore=(int)$beforeCount->fetchColumn();

    $acceptedCrewId = fc_crew_invitation_accept_continuation(
        $pdo,
        (int)$owner['id'],
        (string)$ownerInvite['public_id'],
        0,
        static fn(PDO $db, string $publicId, int $generation): bool => true
    );
    p3db_assert($acceptedCrewId === (int)$crew['id'], 'Already-member acceptance returned wrong Crew.');
    $beforeCount->execute([':c'=>$crew['id'],':u'=>$owner['id']]);
    p3db_assert((int)$beforeCount->fetchColumn() === $countBefore, 'Already-member acceptance created duplicate membership.');
    $role = $pdo->prepare('SELECT role_code FROM crew_memberships WHERE crew_id=:c AND user_id=:u');
    $role->execute([':c'=>$crew['id'],':u'=>$owner['id']]);
    p3db_assert($role->fetchColumn() === 'OWNER', 'Already-member acceptance must preserve Owner role.');

    // Family Alpha rate-limit policies execute on Auth's shared primitive.
    $rateOwner=(int)$owner['id'] + 900000;
    for ($i=0; $i<10; $i++) {
        fc_crew_invitation_rate_limit_issue($pdo,$rateOwner);
    }
    p3db_denied(fn()=>fc_crew_invitation_rate_limit_issue($pdo,$rateOwner),'Issue limiter');

    $rateInvitation='01PASS3RATELIMITRESEND000';
    for ($i=0; $i<5; $i++) {
        fc_crew_invitation_rate_limit_resend($pdo,$rateOwner,$rateInvitation);
    }
    p3db_denied(fn()=>fc_crew_invitation_rate_limit_resend($pdo,$rateOwner,$rateInvitation),'Resend limiter');

    $rawClientSubject='network:198.51.100.77|agent:' . hash('sha256','PASS3 Agent') . '|do-not-persist-raw';
    for ($i=0; $i<20; $i++) {
        fc_crew_invitation_rate_limit_invalid_raw($pdo,$rawClientSubject);
    }
    p3db_denied(fn()=>fc_crew_invitation_rate_limit_invalid_raw($pdo,$rawClientSubject),'Invalid raw lookup limiter');

    $bucket = $pdo->query('SELECT bucket_key_hash FROM security_rate_limit_buckets ORDER BY updated_at DESC LIMIT 1')->fetchColumn();
    p3db_assert(is_string($bucket) && strlen($bucket) === 64 && !str_contains($bucket,'do-not-persist-raw'), 'Rate limiter must persist only keyed evidence.');

    $pdo->rollBack();

    fwrite(STDOUT, "Crew invitation PASS 3 DB proof: PASS\n");
    fwrite(STDOUT, "- Accepted/failed/resend transport truth: PASS\n");
    fwrite(STDOUT, "- Stale/cancelled/expired final validation: PASS\n");
    fwrite(STDOUT, "- Consume-failure rollback / no premature consume: PASS\n");
    fwrite(STDOUT, "- Already-member idempotence / Owner preservation: PASS\n");
    fwrite(STDOUT, "- Issue/resend/invalid-token rate limits: PASS\n");
    fwrite(STDOUT, "- Test data rolled back: PASS\n");
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
