<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Dedicated synthetic database only. Never load .env or use the application connection.
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) require_once $root . '/vendor/autoload.php';
foreach (['config/paths','config/env','config/app','security/redaction','security/validation','support/logger',
    'identity/contracts','identity/ids','identity/users','identity/auth_identities','identity/contact_emails',
    'identity/session_records','identity/audit_events','product/bootstrap'] as $file) {
    require_once $root . '/inc/' . $file . '.php';
}
$_ENV['APP_ENV'] = 'test';
$_ENV['MAIL_DRIVER'] = 'log';

function paf_assert(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
}
function paf_denied(callable $operation, string $expected): void
{
    try { $operation(); } catch (DomainException $error) {
        paf_assert($error->getMessage() === $expected, 'Expected ' . $expected . '; got ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $expected);
}
function paf_key(): string { return bin2hex(random_bytes(16)); }
function paf_rows(PDO $pdo, string $sql, array $values = []): array
{
    $q = $pdo->prepare($sql); $q->execute($values); return $q->fetchAll(PDO::FETCH_ASSOC);
}
function paf_revision(PDO $pdo, array $crew): string
{
    return fc_product_admin_crew_snapshot($pdo, $crew['public_id'])['revision'];
}
/** Include raw domain timestamps, every durable receipt and every SUCCESS audit. */
function paf_evidence(PDO $pdo): array
{
    $tables = ['crews','crew_memberships','crew_current_challenges','challenges','challenge_owner_controls',
        'challenge_rule_versions','challenge_participations','challenge_acceptance_records',
        'challenge_participation_intervals','challenge_participant_offers','challenge_product_events',
        'user_product_contexts','product_admin_operations'];
    $result = [];
    foreach ($tables as $table) {
        $rows = paf_rows($pdo, 'SELECT * FROM ' . $table);
        usort($rows, fn(array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
        $result[$table] = $rows;
    }
    $result['success_audits'] = paf_rows($pdo, "SELECT * FROM audit_events WHERE outcome='SUCCESS' ORDER BY id");
    return $result;
}
function paf_replay(PDO $pdo, callable $operation, array $first, string $label): void
{
    $before = paf_evidence($pdo);
    $allAudits = paf_rows($pdo, 'SELECT * FROM audit_events ORDER BY id');
    $replayed = $operation();
    paf_assert($replayed === array_replace($first, ['replayed'=>true]), $label . ': original result');
    paf_assert(paf_evidence($pdo) === $before, $label . ': no second domain mutation, receipt or success audit');
    paf_assert(paf_rows($pdo, 'SELECT * FROM audit_events ORDER BY id') === $allAudits, $label . ': no audit write');
}

try {
    $dsn = getenv('FC_PRODUCT_ADMIN_TEST_DSN') ?: '';
    if (!preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]+;dbname=fitcrew_product_admin_test;charset=utf8mb4\z/', $dsn)) {
        throw new RuntimeException('Dedicated loopback fitcrew_product_admin_test DSN is required.');
    }
    $pdo = new PDO($dsn, getenv('FC_PRODUCT_ADMIN_TEST_USER') ?: 'root', getenv('FC_PRODUCT_ADMIN_TEST_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    paf_assert($pdo->query('SELECT DATABASE()')->fetchColumn() === 'fitcrew_product_admin_test', 'Dedicated database required');
    paf_assert((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, 'Fresh migrated database with no users required; existing data is never cleared');
    $pdo->exec("SET time_zone='+00:00'");
    $actor = fc_user_create($pdo, 'Product gate actor', 'ACTIVE', 'PLATFORM_SUPER_ADMIN');
    $owner = fc_user_create($pdo, 'Product gate Owner');
    $member = fc_user_create($pdo, 'Product gate Member');
    $removable = fc_user_create($pdo, 'Product gate Removable');
    $identity = fc_auth_identity_create($pdo, $actor['id'], ['provider_key'=>'GOOGLE','issuer'=>'https://accounts.google.com','provider_subject'=>paf_key()]);
    $rawSession = 'product-gate-' . paf_key();
    $session = fc_session_record_create($pdo, $actor['id'], $identity['id'], $rawSession,
        new DateTimeImmutable('+1 hour'), new DateTimeImmutable('+1 day'));
    session_id($rawSession);
    $crew = fc_crew_create($pdo, $owner['id'], 'Product gate Crew');
    $revision = paf_revision($pdo, $crew); $key = paf_key(); $reason = 'Archive replay proof';
    $archive = fn() => fc_product_admin_crew_archive($pdo, $crew['public_id'], $revision, $key, $reason);
    $first = $archive();
    paf_assert($first['replayed'] === false && paf_rows($pdo, 'SELECT crew_status FROM crews WHERE id=?', [$crew['id']])[0]['crew_status'] === 'ARCHIVED', 'Archive succeeds');
    paf_replay($pdo, $archive, $first, 'Archive replay');
    foreach ([['unexpected'=>'changed'], []] as $fields) {
        paf_denied(fn() => fc_product_admin_crew_mutate($pdo, $crew['public_id'], 'archive', $fields, $revision, $key, $fields === [] ? 'Changed reason' : $reason), 'idempotency_conflict');
    }
    paf_denied(fn() => fc_product_admin_crew_archive($pdo, $crew['public_id'], str_repeat('0',64), $key, $reason), 'idempotency_conflict');
    paf_denied(fn() => fc_product_admin_crew_restore($pdo, $crew['public_id'], $revision, $key, $reason), 'idempotency_conflict');
    paf_denied(fn() => fc_product_admin_crew_archive($pdo, $crew['public_id'], paf_revision($pdo,$crew), paf_key(), $reason), 'crew_operation_denied');
    paf_denied(fn() => fc_product_admin_crew_restore($pdo, $crew['public_id'], $revision, paf_key(), $reason), 'stale_crew_state');

    $restoreRevision = paf_revision($pdo,$crew); $restoreKey = paf_key();
    $restore = fn() => fc_product_admin_crew_restore($pdo, $crew['public_id'], $restoreRevision, $restoreKey, 'Restore replay proof');
    $restored = $restore();
    paf_assert($restored['replayed'] === false && paf_rows($pdo, 'SELECT crew_status FROM crews WHERE id=?', [$crew['id']])[0]['crew_status'] === 'ACTIVE', 'Restore succeeds');
    paf_replay($pdo, $restore, $restored, 'Restore replay');
    paf_denied(fn() => fc_product_admin_crew_restore($pdo, $crew['public_id'], $restoreRevision, $restoreKey, 'Changed reason'), 'idempotency_conflict');
    paf_denied(fn() => fc_product_admin_crew_restore($pdo, $crew['public_id'], paf_revision($pdo,$crew), paf_key(), 'New restore'), 'crew_operation_denied');
    paf_denied(fn() => fc_product_admin_crew_archive($pdo, $crew['public_id'], $restoreRevision, paf_key(), 'Stale archive'), 'stale_crew_state');
    paf_replay($pdo, $archive, $first, 'Old archive after restore stays replay-only');

    $unchanged = paf_evidence($pdo);
    $authorityCases = [
        ["UPDATE users SET platform_role_code='PLATFORM_ADMIN' WHERE id=?", "UPDATE users SET platform_role_code='PLATFORM_SUPER_ADMIN' WHERE id=?", $actor['id'], 'crew_operation_denied'],
        ["UPDATE users SET platform_role_code='USER' WHERE id=?", "UPDATE users SET platform_role_code='PLATFORM_SUPER_ADMIN' WHERE id=?", $actor['id'], 'product_admin_operation_denied'],
        ["UPDATE users SET account_status='SUSPENDED' WHERE id=?", "UPDATE users SET account_status='ACTIVE' WHERE id=?", $actor['id'], 'product_admin_operation_denied'],
        ["UPDATE user_auth_identities SET identity_status='REVOKED' WHERE id=?", "UPDATE user_auth_identities SET identity_status='ACTIVE' WHERE id=?", $identity['id'], 'product_admin_operation_denied'],
        ["UPDATE user_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE id=?", "UPDATE user_sessions SET revoked_at=NULL WHERE id=?", $session['id'], 'product_admin_operation_denied'],
        ["UPDATE user_sessions SET idle_expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE id=?", "UPDATE user_sessions SET idle_expires_at=UTC_TIMESTAMP(6)+INTERVAL 1 HOUR WHERE id=?", $session['id'], 'product_admin_operation_denied'],
    ];
    foreach ($authorityCases as [$deny,$recover,$id,$failure]) {
        $pdo->prepare($deny)->execute([$id]);
        paf_denied($archive,$failure); paf_denied($restore,$failure);
        $pdo->prepare($recover)->execute([$id]);
        paf_assert(paf_evidence($pdo) === $unchanged, 'Denied replay leaves Product state, receipts and success audits untouched');
    }
    session_id(''); paf_denied($archive,'product_admin_operation_denied'); session_id($rawSession);

    // All pre-existing Crew operation classes retain successful exact replay.
    $pdo->prepare("UPDATE users SET platform_role_code='PLATFORM_ADMIN' WHERE id=?")->execute([$actor['id']]);
    $editRevision=paf_revision($pdo,$crew); $editKey=paf_key();
    $edit=fn()=>fc_product_admin_crew_edit($pdo,$crew['public_id'],['description'=>'Updated by Admin'],$editRevision,$editKey,'Edit proof');
    paf_replay($pdo,$edit,$edit(),'Admin edit');
    $pdo->prepare("UPDATE users SET platform_role_code='PLATFORM_SUPER_ADMIN' WHERE id=?")->execute([$actor['id']]);
    fc_crew_membership_add_existing($pdo,$owner['id'],$crew['id'],$member['id']);
    fc_crew_membership_add_existing($pdo,$owner['id'],$crew['id'],$removable['id']);
    $historical=fc_challenge_create($pdo,$owner['id'],$crew['id'],'Historical ownership');
    fc_challenge_manage($pdo,$owner['id'],$historical['id'],'archive');
    $challenge=fc_challenge_create($pdo,$owner['id'],$crew['id'],'Current ownership',['planned_start_date'=>'2100-01-01']);
    paf_denied(fn()=>fc_product_admin_crew_archive($pdo,$crew['public_id'],paf_revision($pdo,$crew),paf_key(),'Current Challenge gate'),'crew_has_current_challenge');
    $transferRevision=paf_revision($pdo,$crew); $transferKey=paf_key();
    $transfer=fn()=>fc_product_admin_crew_transfer_owner($pdo,$crew['public_id'],$member['public_id'],$transferRevision,$transferKey,'Transfer proof');
    paf_replay($pdo,$transfer,$transfer(),'Ownership transfer');
    paf_assert((int)paf_rows($pdo,'SELECT owner_user_id FROM challenges WHERE id=?',[$challenge['id']])[0]['owner_user_id']===$member['id'], 'Current Challenge follows new Owner');
    paf_assert((int)paf_rows($pdo,'SELECT owner_user_id FROM challenges WHERE id=?',[$historical['id']])[0]['owner_user_id']===$owner['id'], 'Historical owner preserved');
    $removeRevision=paf_revision($pdo,$crew); $removeKey=paf_key();
    $remove=fn()=>fc_product_admin_crew_member_remove($pdo,$crew['public_id'],$removable['public_id'],$removeRevision,$removeKey,'Remove proof');
    paf_replay($pdo,$remove,$remove(),'Member removal');

    $published=fc_challenge_rule_current_draft($pdo,$challenge['id']);
    fc_challenge_rule_publish($pdo,$member['id'],$challenge['id'],(int)$published['id']);
    fc_challenge_offer_participation($pdo,$member['id'],$challenge['id'],$owner['public_id']);
    $offer=fc_challenge_offer_for_user($pdo,$challenge['id'],$owner['id']);
    fc_challenge_accept_participation($pdo,$owner['id'],$challenge['id'],(int)$published['id'],true,[],(string)$offer['public_id']);

    // Existing Challenge receipts and both sides of the terminal operation contract.
    $snap=fc_product_admin_challenge_snapshot($pdo,$challenge['public_id']); $nameKey=paf_key();
    $rename=fn()=>fc_product_admin_challenge_edit_name($pdo,$challenge['public_id'],'Renamed Challenge',$snap['revision'],$nameKey,'Name proof');
    paf_replay($pdo,$rename,$rename(),'Challenge name');
    $snap=fc_product_admin_challenge_snapshot($pdo,$challenge['public_id']); $ruleKey=paf_key();
    $ruleEdit=fn()=>fc_product_admin_challenge_rule_draft_edit($pdo,$challenge['public_id'],['duration_days'=>70],$snap['revision'],$ruleKey,'Rule draft proof');
    paf_replay($pdo,$ruleEdit,$ruleEdit(),'Challenge Rule draft');
    paf_assert(fc_challenge_rule_current_published($pdo,$challenge['id'])['id']===$published['id'],'Published Rule authority survives correction draft');
    $snap=fc_product_admin_challenge_snapshot($pdo,$challenge['public_id']); $participantKey=paf_key();
    $participantRemove=fn()=>fc_product_admin_challenge_participant_remove($pdo,$challenge['public_id'],$owner['public_id'],$snap['revision'],$participantKey,'Participant removal proof');
    paf_replay($pdo,$participantRemove,$participantRemove(),'Challenge participant removal');
    $snap=fc_product_admin_challenge_snapshot($pdo,$challenge['public_id']); $challengeArchiveKey=paf_key();
    $challengeArchive=fn()=>fc_product_admin_challenge_archive($pdo,$challenge['public_id'],$snap['revision'],$challengeArchiveKey,'Challenge archive proof');
    paf_replay($pdo,$challengeArchive,$challengeArchive(),'Challenge archive');
    $snap=fc_product_admin_challenge_snapshot($pdo,$challenge['public_id']); $unarchiveKey=paf_key();
    paf_assert(in_array('unarchive',$snap['allowed_operations'],true),'Non-terminal unarchive is advertised');
    $unarchive=fn()=>fc_product_admin_challenge_unarchive($pdo,$challenge['public_id'],$snap['revision'],$unarchiveKey,'Challenge unarchive proof');
    paf_replay($pdo,$unarchive,$unarchive(),'Non-terminal Challenge unarchive');
    paf_assert(fc_challenge_is_current_for_crew($pdo,$crew['id'],$challenge['id']),'Non-terminal unarchive restores current authority');
    $pdo->prepare("UPDATE challenges SET lifecycle_status='COMPLETED',completed_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$challenge['id']]);
    fc_product_atomic($pdo,fn()=>fc_crew_current_challenge_release($pdo,$crew['id'],$challenge['id']));
    $snap=fc_product_admin_challenge_snapshot($pdo,$challenge['public_id']);
    paf_assert($snap['allowed_operations']===['edit_name','archive'],'Completed operations agree with terminal contract');
    $terminalRevision=$snap['revision']; $terminalKey=paf_key();
    $terminalArchive=fn()=>fc_product_admin_challenge_archive($pdo,$challenge['public_id'],$terminalRevision,$terminalKey,'Archive terminal history');
    paf_replay($pdo,$terminalArchive,$terminalArchive(),'Completed Challenge archive');
    $snap=fc_product_admin_challenge_snapshot($pdo,$challenge['public_id']);
    $terminalBefore=paf_evidence($pdo);
    paf_assert(!in_array('unarchive',$snap['allowed_operations'],true),'Completed archived Challenge does not advertise restoration');
    paf_denied(fn()=>fc_product_admin_challenge_unarchive($pdo,$challenge['public_id'],$snap['revision'],paf_key(),'Forbidden terminal restore'),'challenge_operation_denied');
    paf_assert(fc_crew_current_challenge($pdo,$crew['id'])===null && paf_evidence($pdo)===$terminalBefore,'Terminal restore denial preserves history and no current authority');
    $snap=fc_product_admin_challenge_snapshot($pdo,$historical['public_id']); $endKey=paf_key();
    $end=fn()=>fc_product_admin_challenge_end($pdo,$historical['public_id'],'Fixture ended',$snap['revision'],$endKey,'End proof');
    paf_replay($pdo,$end,$end(),'Challenge end');
    echo "Product ADMIN-2 database gate proof: PASS\n";
    echo "- Crew archive/restore exact replay, unchanged domain/receipt/audit rows: PASS\n";
    echo "- Changed digest conflicts, new-request state/revision gates: PASS\n";
    echo "- Current role/account/identity/session authorization on replay: PASS\n";
    echo "- Crew edit/transfer/removal and Challenge receipt regressions: PASS\n";
    echo "- Completed allowed operations/runtime/history alignment: PASS\n";
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'[FAIL] '.$error->getMessage().PHP_EOL); exit(1);
}
