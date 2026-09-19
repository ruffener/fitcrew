<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/product/bootstrap.php';

function cias_assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function cias_null(mixed $value, string $message): void
{
    if ($value !== null) {
        throw new RuntimeException($message);
    }
}

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "[BLOCKED] PDO MySQL driver is required for the invitation Auth snapshot proof.\n");
    exit(2);
}

$pdo = fc_db();

// Locking mode must fail closed before any fixture transaction exists.
try {
    fc_crew_invitation_auth_snapshot($pdo, '01AAAAAAAAAAAAAAAAAAAAAAAA', 0, true);
    throw new RuntimeException('Admission lock unexpectedly succeeded without an active transaction.');
} catch (DomainException $expected) {
    cias_assert(
        $expected->getMessage() === 'Invitation admission lock requires an active database transaction.',
        'Admission lock failed with an unexpected error.'
    );
}

$pdo->beginTransaction();
try {
    $owner = fc_user_create($pdo, 'Invitation Snapshot Owner');
    $acceptor = fc_user_create($pdo, 'Invitation Snapshot Acceptor');
    $crew = fc_crew_create($pdo, $owner['id'], 'Invitation Snapshot Crew');
    $challenge = fc_challenge_create($pdo, $owner['id'], $crew['id'], 'Invitation Snapshot Challenge', ['planned_start_date'=>'2100-01-01']);
    $draft = fc_challenge_rule_current_draft($pdo, $challenge['id']);
    fc_challenge_rule_publish($pdo, $owner['id'], $challenge['id'], (int) $draft['id']);

    $current = fc_crew_invitation_create($pdo, $owner['id'], $crew['id'], $challenge['id'], 'current@example.test');
    $snapshot = fc_crew_invitation_auth_snapshot($pdo, (string) $current['public_id'], 0);
    cias_assert($snapshot !== null, 'Current pending invitation must return a snapshot.');
    cias_assert(
        array_keys($snapshot) === ['invitation_public_id', 'generation', 'expires_at'],
        'Snapshot must expose only the approved minimal fields.'
    );
    cias_assert($snapshot['invitation_public_id'] === $current['public_id'], 'Snapshot public ID mismatch.');
    cias_assert($snapshot['generation'] === 0, 'Initial invitation generation must be zero.');
    cias_assert($snapshot['expires_at'] !== '', 'Snapshot expiration is required.');
    cias_null(
        fc_crew_invitation_auth_snapshot($pdo, (string) $current['public_id'], 1),
        'Wrong generation must fail closed.'
    );

    // Locking mode uses the same qualifying read inside the already-active caller transaction.
    $locked = fc_crew_invitation_auth_snapshot($pdo, (string) $current['public_id'], 0, true);
    cias_assert($locked === $snapshot, 'Locking snapshot must return the same minimal current truth.');

    // Resend rotates token authority by incrementing resend_count/generation.
    fc_crew_invitation_resend($pdo, $owner['id'], $crew['id'], (string) $current['public_id']);
    cias_null(
        fc_crew_invitation_auth_snapshot($pdo, (string) $current['public_id'], 0),
        'Prior generation must fail after resend.'
    );
    $resend = fc_crew_invitation_auth_snapshot($pdo, (string) $current['public_id'], 1);
    cias_assert($resend !== null && $resend['generation'] === 1, 'Current resend generation must pass.');

    // Cancelled invitation.
    fc_crew_invitation_cancel($pdo, $owner['id'], $crew['id'], (string) $current['public_id']);
    cias_null(
        fc_crew_invitation_auth_snapshot($pdo, (string) $current['public_id'], 1),
        'Cancelled invitation must fail closed.'
    );

    // Accepted invitation.
    $accepted = fc_crew_invitation_create($pdo, $owner['id'], $crew['id'], $challenge['id'], 'accepted@example.test');
    $pdo->prepare("UPDATE crew_invitations SET invitation_status='ACCEPTED',accepted_by_user_id=:u,accepted_at=CURRENT_TIMESTAMP(6) WHERE public_id=:p")
        ->execute([':u'=>$acceptor['id'], ':p'=>(string)$accepted['public_id']]);
    cias_null(
        fc_crew_invitation_auth_snapshot($pdo, (string) $accepted['public_id'], 0),
        'Accepted invitation must fail closed.'
    );

    // Explicit EXPIRED state.
    $expiredStatus = fc_crew_invitation_create($pdo, $owner['id'], $crew['id'], $challenge['id'], 'expired-status@example.test');
    $pdo->prepare("UPDATE crew_invitations SET invitation_status='EXPIRED' WHERE public_id=:p")
        ->execute([':p' => (string) $expiredStatus['public_id']]);
    cias_null(
        fc_crew_invitation_auth_snapshot($pdo, (string) $expiredStatus['public_id'], 0),
        'EXPIRED invitation status must fail closed.'
    );

    // PENDING row with elapsed expires_at must fail without mutating product state.
    $elapsed = fc_crew_invitation_create($pdo, $owner['id'], $crew['id'], $challenge['id'], 'elapsed@example.test');
    $pdo->prepare("UPDATE crew_invitations SET expires_at=DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE public_id=:p")
        ->execute([':p' => (string) $elapsed['public_id']]);
    cias_null(
        fc_crew_invitation_auth_snapshot($pdo, (string) $elapsed['public_id'], 0),
        'Elapsed PENDING invitation must fail closed.'
    );
    $state = $pdo->prepare('SELECT invitation_status FROM crew_invitations WHERE public_id=:p');
    $state->execute([':p' => (string) $elapsed['public_id']]);
    cias_assert($state->fetchColumn() === 'PENDING', 'Read-only Auth snapshot must not mutate expired product state.');

    $pdo->rollBack();

    fwrite(STDOUT, "Crew invitation Auth snapshot DB proof: PASS\n");
    fwrite(STDOUT, "- Current PENDING / unexpired / matching generation: PASS\n");
    fwrite(STDOUT, "- Wrong generation / resend stale generation: PASS\n");
    fwrite(STDOUT, "- CANCELLED / ACCEPTED / EXPIRED / elapsed PENDING rejection: PASS\n");
    fwrite(STDOUT, "- Locking requires caller transaction / FOR UPDATE path exercised: PASS\n");
    fwrite(STDOUT, "- Minimal three-field snapshot / no mutation: PASS\n");
    fwrite(STDOUT, "- Test data rolled back: PASS\n");
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
