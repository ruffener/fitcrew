<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Intentionally does not load .env or the application connection. Tests cannot target production.
require_once dirname(__DIR__) . '/inc/security/redaction.php';
require_once dirname(__DIR__) . '/inc/security/validation.php';
require_once dirname(__DIR__) . '/inc/security/escape.php';
require_once dirname(__DIR__) . '/inc/identity/contracts.php';
require_once dirname(__DIR__) . '/inc/identity/auth_identities.php';
require_once dirname(__DIR__) . '/inc/identity/audit_events.php';
require_once dirname(__DIR__) . '/inc/http/csrf.php';
require_once dirname(__DIR__) . '/inc/admin/authorization.php';
require_once dirname(__DIR__) . '/inc/admin/audit.php';
require_once dirname(__DIR__) . '/inc/admin/roles.php';
require_once dirname(__DIR__) . '/inc/admin/queries.php';
require_once dirname(__DIR__) . '/inc/admin/presentation.php';

function admin_test_db(): PDO
{
    $dsn = getenv('FC_ADMIN_TEST_DSN') ?: '';
    if (!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=fitcrew_admin1_test;charset=utf8mb4$/D', $dsn)) {
        throw new RuntimeException('Set FC_ADMIN_TEST_DSN for the dedicated localhost fitcrew_admin1_test database.');
    }
    $p = new PDO($dsn, getenv('FC_ADMIN_TEST_USER') ?: 'root', getenv('FC_ADMIN_TEST_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    if ($p->query('SELECT DATABASE()')->fetchColumn() !== 'fitcrew_admin1_test') { throw new RuntimeException('Unsafe test target.'); }
    $p->exec("SET time_zone='+00:00'");
    return $p;
}

function admin_test_assert(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS: $message\n";
}

function admin_test_denied(callable $call, string $reason): void
{
    try { $call(); } catch (FcAdminDenied $e) {
        admin_test_assert($e->reason === $reason, "Denied: $reason"); return;
    }
    throw new RuntimeException('Expected denial: ' . $reason);
}

function admin_test_fixture(PDO $p): array
{
    // Only this dedicated disposable schema is cleared; no application cleanup feature exists.
    $p->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['audit_events','user_sessions','user_auth_identities','user_contact_emails','users','crew_invitations','crew_memberships','crews','challenges','challenge_participations'] as $table) {
        $p->exec('DELETE FROM ' . $table);
    }
    $p->exec('SET FOREIGN_KEY_CHECKS=1');
    $names=['super','admin','user','inactive']; $fixtures=[];
    foreach ($names as $n=>$name) {
        $id=$n+1; $public='01ARZ3NDEKTSV4RRFFQ69G5FA'.($n+1);
        $role=['PLATFORM_SUPER_ADMIN','PLATFORM_ADMIN','USER','USER'][$n];
        $s=$p->prepare('INSERT INTO users (id,public_id,display_name,account_status,platform_role_code) VALUES (?,?,?,?,?)');
        $s->execute([$id,$public,$name,$name==='inactive'?'SUSPENDED':'ACTIVE',$role]);
        $s=$p->prepare("INSERT INTO user_auth_identities (id,user_id,provider_key,issuer,provider_subject,email_at_provider,provider_email_verified) VALUES (?,?,'GOOGLE','https://accounts.google.com',?,?,1)");
        $s->execute([$id,$id,'SECRET-SUBJECT-'.$id,$name.'@example.test']);
        $raw='admin1-fixture-'.$name.'-'.bin2hex(random_bytes(12));
        $s=$p->prepare('INSERT INTO user_sessions (id,user_id,auth_identity_id,session_id_hash,idle_expires_at,absolute_expires_at) VALUES (?,?,?,?,CURRENT_TIMESTAMP(6)+INTERVAL 1 HOUR,CURRENT_TIMESTAMP(6)+INTERVAL 1 DAY)');
        $s->execute([$id,$id,$id,hash('sha256',$raw)]);
        $fixtures[$name]=['user_id'=>$id,'public_id'=>$public,'auth_identity_id'=>$id,'session_record_id'=>$id,'raw'=>$raw];
    }
    $p->exec("INSERT INTO crews (id,public_id,display_name,owner_user_id) VALUES (1,'01ARZ3NDEKTSV4RRFFQ69G5FC1','Test Crew',3)");
    $p->exec("INSERT INTO crew_memberships (crew_id,user_id,role_code) VALUES (1,3,'OWNER')");
    $p->exec("INSERT INTO challenges (id,public_id,crew_id,owner_user_id,display_name,lifecycle_status) VALUES (1,'01ARZ3NDEKTSV4RRFFQ69G5FH1',1,3,'Alpha Challenge','LAUNCHED')");
    $p->exec("INSERT INTO crew_invitations (id,public_id,crew_id,challenge_id,invited_email,invited_by_user_id,token_hash,expires_at,transport_status) VALUES (1,'01ARZ3NDEKTSV4RRFFQ69G5FJ1',1,1,'invite@example.test',3,REPEAT('a',64),CURRENT_TIMESTAMP(6)-INTERVAL 1 HOUR,'TRANSPORT_ACCEPTED')");
    $p->exec("INSERT INTO crew_invitations (id,public_id,crew_id,invited_email,invited_by_user_id,token_hash,expires_at,transport_status) VALUES (2,'01ARZ3NDEKTSV4RRFFQ69G5FJ2',1,'legacy@example.test',3,REPEAT('b',64),CURRENT_TIMESTAMP(6)+INTERVAL 1 HOUR,'PENDING_SEND')");
    return $fixtures;
}
