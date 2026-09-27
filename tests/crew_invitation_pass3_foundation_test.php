<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }

/*
 * Committed transport fixtures require an isolated database, not rollback around delivery.
 * Apply existing migrations to a fresh, empty fitcrew_crew_invitation_pass3_test database.
 * Set process-only FC_CREW_PASS3_TEST_DSN to:
 * mysql:host=127.0.0.1;port=<port>;dbname=fitcrew_crew_invitation_pass3_test;charset=utf8mb4
 * Set FC_CREW_PASS3_TEST_USER / FC_CREW_PASS3_TEST_PASSWORD for that database.
 * This test never uses fc_db(), clears existing data, or sends external email.
 * Synthetic committed evidence is retained; recreate only that disposable database to rerun.
 */
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/product/bootstrap.php';

function p3db_assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function p3db_denied(callable $fn, string $label, ?string $expected = null): Throwable
{
    try { $fn(); } catch (DomainException|InvalidArgumentException|LogicException $error) {
        p3db_assert($expected === null || $error->getMessage() === $expected, $label . ': unexpected rejection: ' . $error->getMessage());
        return $error;
    }
    throw new RuntimeException($label . ': denial expected');
}
function p3db_connection(): PDO
{
    $dsn = getenv('FC_CREW_PASS3_TEST_DSN') ?: '';
    if (!preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]+;dbname=fitcrew_crew_invitation_pass3_test;charset=utf8mb4\z/', $dsn)) {
        throw new RuntimeException('Dedicated loopback fitcrew_crew_invitation_pass3_test DSN is required.');
    }
    $pdo = new PDO($dsn, getenv('FC_CREW_PASS3_TEST_USER') ?: 'root', getenv('FC_CREW_PASS3_TEST_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    p3db_assert($pdo->query('SELECT DATABASE()')->fetchColumn() === 'fitcrew_crew_invitation_pass3_test', 'Dedicated database required.');
    $pdo->exec("SET time_zone='+00:00'");
    return $pdo;
}
function p3db_invitation(PDO $pdo, array $invitation): array
{
    $q = $pdo->prepare('SELECT * FROM crew_invitations WHERE public_id=?');
    $q->execute([$invitation['public_id']]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
}
function p3db_proof(PDO $pdo, array $invitation): ?array
{
    $q = $pdo->prepare('SELECT * FROM auth_invitation_email_proofs WHERE invitation_public_id=? AND invitation_generation=?');
    $q->execute([$invitation['public_id'], $invitation['generation']]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
function p3db_pending(PDO $observer, array $invitation): void
{
    $row = p3db_invitation($observer, $invitation);
    p3db_assert($row !== [] && (int)$row['resend_count'] === (int)$invitation['generation'], 'Issuance/generation must be visible on an independent connection before delivery.');
    p3db_assert($row['transport_status'] === 'PENDING_SEND' && $row['sent_at'] === null && $row['transport_message_id'] === null, 'Committed pending issuance must not look sent.');
    p3db_assert(hash_equals($row['token_hash'], hash('sha256', $invitation['token'])), 'Committed issuance must bind the current token.');
}
function p3db_transaction_guard(PDO $pdo, PDO $observer, array $invitation): void
{
    $before = p3db_invitation($observer, $invitation);
    $proofBefore = p3db_proof($observer, $invitation);
    $called = false;
    $pdo->beginTransaction();
    try {
        p3db_denied(function () use ($pdo, $invitation, &$called): void {
            fc_crew_invitation_deliver($pdo, $invitation, static function (array $message) use (&$called): array {
                $called = true;
                return ['accepted'=>true, 'driver'=>'postmark', 'message_id'=>'must-not-send'];
            });
        }, 'Caller transaction transport guard', 'Invitation delivery must begin after issuance and proof registration are committed.');
        p3db_assert($pdo->inTransaction() && !$called, 'Guard must preserve caller transaction and reject before invoking transport.');
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
    p3db_assert(p3db_invitation($observer, $invitation) === $before && p3db_proof($observer, $invitation) === $proofBefore, 'Rejected transactional delivery must not write transport/proof state.');
}
/** Assert durable state at the real service's injectable external-transport boundary. */
function p3db_transport_observe(PDO $pdo, PDO $observer, array $invitation, array $message): void
{
    p3db_assert(!$pdo->inTransaction() && !$observer->inTransaction(), 'Transport must begin outside a database transaction.');
    p3db_pending($observer, $invitation);
    $proof = p3db_proof($observer, $invitation);
    $row = p3db_invitation($observer, $invitation);
    p3db_assert($proof !== null, 'Auth proof must already be committed and visible to another connection at transport entry.');
    p3db_assert($proof['token_hash'] === $row['token_hash'] && $proof['email_canonical'] === $invitation['email']
        && $proof['expires_at'] === $row['expires_at'] && $proof['consumed_at'] === null, 'Committed proof must preserve token/email/generation/expiry binding and remain unconsumed.');
    p3db_assert($message['to'] === $invitation['email']
        && str_contains($message['text_body'], '/crew-invite.php#token=' . rawurlencode($invitation['token']))
        && !str_contains($message['text_body'], '/crew-invite.php?token='), 'Transport must receive the real fragment-token invitation message.');
    p3db_denied(fn()=>fc_auth_invitation_email_read($observer, $proof['public_id']), 'Unsent proof admission', 'invitation_email_invalid');
}
function p3db_accepted(PDO $observer, array $invitation, string $messageId): void
{
    $row = p3db_invitation($observer, $invitation);
    p3db_assert($row['transport_status'] === 'TRANSPORT_ACCEPTED' && $row['transport_driver'] === 'postmark'
        && $row['transport_message_id'] === $messageId && $row['sent_at'] !== null && $row['transport_attempted_at'] !== null, 'Accepted transport evidence must be durable.');
    $proof = p3db_proof($observer, $invitation);
    p3db_assert($proof !== null && fc_auth_invitation_email_read($observer, $proof['public_id'])['public_id'] === $proof['public_id'], 'Accepted current-generation Auth proof must remain usable.');
}

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "[BLOCKED] PDO MySQL driver is required for the PASS 3 DB proof.\n"); exit(2);
}
try {
    $pdo = p3db_connection();
    $observer = p3db_connection();
    foreach (['users','crew_invitations','auth_invitation_email_proofs','security_rate_limit_buckets'] as $table) {
        p3db_assert((int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === 0, 'Fresh migrated test database required; existing rows are never cleared.');
    }
    $pdo->beginTransaction();
    $owner = fc_user_create($pdo, 'PASS3 Owner');
    $crew = fc_crew_create($pdo, (int)$owner['id'], 'PASS3 Crew');
    $challenge = fc_challenge_create($pdo, (int)$owner['id'], (int)$crew['id'], 'PASS3 Challenge', ['planned_start_date'=>'2100-01-01']);
    $draft = fc_challenge_rule_current_draft($pdo, (int)$challenge['id']);
    fc_challenge_rule_publish($pdo, (int)$owner['id'], (int)$challenge['id'], (int)$draft['id']);
    $pdo->commit();

    // Production-shaped path: issuance owns/commits its transaction; delivery registers
    // and commits the Auth proof itself before invoking the supplied synthetic sender.
    $invite = fc_crew_invitation_create($pdo, (int)$owner['id'], (int)$crew['id'], (int)$challenge['id'], 'alpha@example.com');
    p3db_assert(!$pdo->inTransaction(), 'Issuance must commit before calling delivery.');
    p3db_pending($observer, $invite);
    p3db_assert(p3db_proof($observer, $invite) === null, 'Delivery must exercise real Auth proof registration.');
    p3db_transaction_guard($pdo, $observer, $invite);
    $sent = 0;
    fc_crew_invitation_deliver($pdo, $invite, static function (array $message) use ($pdo, $observer, $invite, &$sent): array {
        p3db_transport_observe($pdo, $observer, $invite, $message);
        $sent++;
        return ['accepted'=>true, 'driver'=>'postmark', 'message_id'=>'pm-pass3'];
    });
    p3db_assert($sent === 1, 'Accepted transport callback must run once.');
    p3db_accepted($observer, $invite, 'pm-pass3');
    $originalProof = p3db_proof($observer, $invite);

    $resend = fc_crew_invitation_resend($pdo, (int)$owner['id'], (int)$crew['id'], (string)$invite['public_id']);
    p3db_assert(!$pdo->inTransaction() && (int)$resend['generation'] === 1, 'Resend must commit the advanced generation before delivery.');
    p3db_pending($observer, $resend);
    p3db_assert(fc_crew_invitation_auth_snapshot($observer, (string)$invite['public_id'], 0) === null, 'Resend must invalidate prior generation.');
    p3db_assert(fc_crew_invitation_auth_snapshot($observer, (string)$invite['public_id'], 1) !== null, 'New resend generation must be current.');
    p3db_assert(fc_crew_invitation_find_token($observer, $invite['token']) === null, 'Resend must invalidate the original token.');
    p3db_denied(fn()=>fc_auth_invitation_email_read($observer, $originalProof['public_id']), 'Stale generation Auth proof', 'invitation_email_invalid');
    p3db_transaction_guard($pdo, $observer, $resend);
    $failedCalls = 0;
    $error = p3db_denied(function () use ($pdo, $observer, $resend, &$failedCalls): void {
        fc_crew_invitation_deliver($pdo, $resend, static function (array $message) use ($pdo, $observer, $resend, &$failedCalls): array {
            p3db_transport_observe($pdo, $observer, $resend, $message);
            $failedCalls++;
            throw new RuntimeException('PASS3 synthetic transport failure');
        });
    }, 'Synthetic transport failure', 'The invitation is pending, but email transport failed. Use Resend to try again.');
    p3db_assert($failedCalls === 1 && $error->getPrevious()?->getMessage() === 'PASS3 synthetic transport failure', 'Failure proof must reach transport with durable issuance/proof state.');
    $row = p3db_invitation($observer, $resend);
    p3db_assert($row['transport_status'] === 'TRANSPORT_FAILED' && $row['transport_driver'] === fc_mail_config()['driver']
        && $row['transport_message_id'] === null && $row['sent_at'] === null, 'Failed transport must not look sent.');
    $failedProof = p3db_proof($observer, $resend);
    p3db_denied(fn()=>fc_auth_invitation_email_read($observer, $failedProof['public_id']), 'Failed transport Auth proof', 'invitation_email_invalid');

    // Successful resend after failure must use another committed generation/proof.
    $retry = fc_crew_invitation_resend($pdo, (int)$owner['id'], (int)$crew['id'], (string)$invite['public_id']);
    p3db_assert(!$pdo->inTransaction() && (int)$retry['generation'] === 2, 'Retry generation must commit before transport.');
    p3db_pending($observer, $retry);
    p3db_assert(fc_crew_invitation_auth_snapshot($observer, (string)$invite['public_id'], 1) === null
        && fc_crew_invitation_find_token($observer, $resend['token']) === null, 'Retry must invalidate the failed generation and token.');
    $resent = 0;
    fc_crew_invitation_deliver($pdo, $retry, static function (array $message) use ($pdo, $observer, $retry, &$resent): array {
        p3db_transport_observe($pdo, $observer, $retry, $message);
        $resent++;
        return ['accepted'=>true, 'driver'=>'postmark', 'message_id'=>'pm-pass3-resend'];
    });
    p3db_assert($resent === 1, 'Resend transport callback must run once.');
    p3db_accepted($observer, $retry, 'pm-pass3-resend');

    // The remaining snapshot/rate-limit probes do not transport and still roll back.
    $pdo->beginTransaction();
    $cancel = fc_crew_invitation_create($pdo, (int)$owner['id'], (int)$crew['id'], (int)$challenge['id'], 'cancel@example.com');
    fc_crew_invitation_cancel($pdo, (int)$owner['id'], (int)$crew['id'], (string)$cancel['public_id']);
    p3db_assert(fc_crew_invitation_auth_snapshot($pdo, (string)$cancel['public_id'], 0) === null, 'Cancelled invitation must fail snapshot.');
    $expired = fc_crew_invitation_create($pdo, (int)$owner['id'], (int)$crew['id'], (int)$challenge['id'], 'expired@example.com');
    $pdo->prepare('UPDATE crew_invitations SET expires_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE public_id=?')->execute([$expired['public_id']]);
    p3db_assert(fc_crew_invitation_auth_snapshot($pdo, (string)$expired['public_id'], 0) === null, 'Expired invitation must fail snapshot.');
    $rateOwner = (int)$owner['id'] + 900000;
    for ($i=0; $i<10; $i++) fc_crew_invitation_rate_limit_issue($pdo, $rateOwner);
    p3db_denied(fn()=>fc_crew_invitation_rate_limit_issue($pdo, $rateOwner), 'Issue limiter');
    $rateInvitation = '01PASS3RATELIMITRESEND000';
    for ($i=0; $i<5; $i++) fc_crew_invitation_rate_limit_resend($pdo, $rateOwner, $rateInvitation);
    p3db_denied(fn()=>fc_crew_invitation_rate_limit_resend($pdo, $rateOwner, $rateInvitation), 'Resend limiter');
    $raw = 'network:198.51.100.77|agent:' . hash('sha256', 'PASS3 Agent') . '|do-not-persist-raw';
    for ($i=0; $i<20; $i++) fc_crew_invitation_rate_limit_invalid_raw($pdo, $raw);
    p3db_denied(fn()=>fc_crew_invitation_rate_limit_invalid_raw($pdo, $raw), 'Invalid raw lookup limiter');
    $bucket = $pdo->query('SELECT bucket_key_hash FROM security_rate_limit_buckets ORDER BY updated_at DESC LIMIT 1')->fetchColumn();
    p3db_assert(is_string($bucket) && strlen($bucket) === 64 && !str_contains($bucket, 'do-not-persist-raw'), 'Rate limiter must persist only keyed evidence.');
    $pdo->rollBack();
    p3db_assert(p3db_invitation($observer, $cancel) === [] && p3db_invitation($observer, $expired) === []
        && (int)$observer->query('SELECT COUNT(*) FROM security_rate_limit_buckets')->fetchColumn() === 0, 'Non-transport probes must roll back.');
    fwrite(STDOUT, "Crew invitation PASS 3 DB proof: PASS\n- independent-connection proof of committed issuance and Auth proof before transport: PASS\n- caller-transaction delivery rejection / sender never invoked: PASS\n- accepted/failed/resend transport truth: PASS\n- fragment token / current and stale generation Auth-proof behavior: PASS\n- cancelled / expired snapshot rejection and rate limits: PASS\n- non-transport probes rolled back; committed evidence isolated in dedicated test database: PASS\n");
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL); exit(1);
}
