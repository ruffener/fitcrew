<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// No .env load, no application connection, and no network mail delivery.
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) require_once $root . '/vendor/autoload.php';
foreach (['config/paths','config/env','config/app','security/redaction','security/validation','support/logger',
    'identity/contracts','identity/ids','identity/users','identity/auth_identities','identity/contact_emails',
    'identity/session_records','identity/audit_events','auth/sessions','auth/user_operations'] as $file) {
    require_once $root . '/inc/' . $file . '.php';
}
$_ENV['APP_ENV'] = 'test'; $_ENV['APP_URL'] = 'http://127.0.0.1'; $_ENV['MAIL_DRIVER'] = 'log';

function uop_db(): PDO
{
    $dsn = getenv('FC_AUTH_USER_OPS_TEST_DSN') ?: '';
    if (!preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]+;dbname=fitcrew_auth_admin2a_test;charset=utf8mb4\z/', $dsn)) {
        throw new RuntimeException('Dedicated loopback fitcrew_auth_admin2a_test DSN is required.');
    }
    $pdo = new PDO($dsn, getenv('FC_AUTH_USER_OPS_TEST_USER') ?: 'root', getenv('FC_AUTH_USER_OPS_TEST_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'fitcrew_auth_admin2a_test') throw new RuntimeException('Unsafe test target.');
    $pdo->exec("SET time_zone='+00:00'");
    return $pdo;
}
function uop_assert(bool $value, string $label): void
{
    if (!$value) throw new RuntimeException($label);
    echo 'PASS: ' . $label . PHP_EOL;
}
function uop_denied(callable $fn, string $reason): void
{
    try { $fn(); } catch (DomainException|InvalidArgumentException $e) {
        uop_assert($e->getMessage() === $reason, 'Denied: ' . $reason . ' (actual ' . $e->getMessage() . ')'); return;
    }
    throw new RuntimeException('Expected rejection: ' . $reason);
}
function uop_key(): string { return bin2hex(random_bytes(16)); }
function uop_revision(PDO $pdo, array $user): string { return fc_auth_user_operations_snapshot($pdo, $user['public_id'])['revision']; }
function uop_login(array $user): void { session_id($user['session']); }
function uop_fixture(PDO $pdo): array
{
    $pdo->exec('DROP TRIGGER IF EXISTS uop_audit_failure');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['auth_contact_verifications','auth_user_operations','audit_events','user_sessions','user_contact_emails','user_auth_identities','users'] as $table) $pdo->exec('DELETE FROM ' . $table);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $f = [];
    foreach (['super' => 'PLATFORM_SUPER_ADMIN', 'admin' => 'PLATFORM_ADMIN', 'user' => 'USER', 'other' => 'USER'] as $name => $role) {
        $u = fc_user_create($pdo, $name, 'ACTIVE', $role, 'UTC', 'en');
        $identity = fc_auth_identity_create($pdo, $u['id'], ['provider_key'=>'GOOGLE','issuer'=>'https://accounts.google.com','provider_subject'=>uop_key()]);
        $raw = 'uop-test-' . uop_key();
        fc_session_record_create($pdo, $u['id'], $identity['id'], $raw, new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')), new DateTimeImmutable('+1 day', new DateTimeZone('UTC')));
        $f[$name] = $u + ['session' => $raw, 'identity_id' => $identity['id']];
    }
    return $f;
}
/** Synthetic mailbox proof known only to this isolated test process. */
function uop_verification(PDO $pdo, array $f, string $email): array
{
    fc_user_ops_begin($pdo);
    [$actor, $target] = fc_user_ops_context($pdo, $f['user']['public_id']);
    $pdo->prepare("UPDATE auth_contact_verifications SET created_at=UTC_TIMESTAMP(6)-INTERVAL 2 MINUTE,expires_at=UTC_TIMESTAMP(6)+INTERVAL 13 MINUTE WHERE target_user_id=?")->execute([$target['id']]);
    $result = fc_user_contact_issue_locked($pdo, $actor, $target, $email, 'Isolated proof');
    $pdo->prepare("UPDATE auth_contact_verifications SET status='ISSUED' WHERE public_id=?")->execute([$result['verification_public_id']]);
    $pdo->commit();
    return $result;
}
