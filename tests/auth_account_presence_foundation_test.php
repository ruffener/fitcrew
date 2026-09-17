<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__) . '/inc/bootstrap.php';

function fc_presence_assert(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "[BLOCKED] PDO MySQL and a migrated local test database are required.\n");
    exit(2);
}

$pdo = fc_db();
$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(8));
    $userIds = [];
    foreach (['ACTIVE', 'SUSPENDED', 'DEACTIVATED'] as $status) {
        $statement = $pdo->prepare(
            'INSERT INTO users (public_id, display_name, account_status, platform_role_code) ' .
            "VALUES (:p, 'Private presence fixture', :s, 'USER')"
        );
        $statement->execute([':p' => '01' . strtoupper(bin2hex(random_bytes(12))), ':s' => $status]);
        $userIds[$status] = (int) $pdo->lastInsertId();
    }
    $verifiedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    $cases = [];
    foreach ($userIds as $status => $userId) {
        $email = strtolower($status) . '.' . $suffix . '@example.test';
        fc_contact_email_create($pdo, $userId, $email, 'USER', false, 'VERIFIED', $verifiedAt);
        $cases[$email] = 'KNOWN_ACCOUNT';
    }
    $cases['  ACTIVE.' . strtoupper($suffix) . '@EXAMPLE.TEST  '] = 'KNOWN_ACCOUNT';
    $userId = $userIds['ACTIVE'];
    $unverified = 'unverified.' . $suffix . '@example.test';
    fc_contact_email_create($pdo, $userId, $unverified, 'USER');
    $cases[$unverified] = 'UNKNOWN';
    $removed = 'removed.' . $suffix . '@example.test';
    $contact = fc_contact_email_create($pdo, $userId, $removed, 'USER', false, 'VERIFIED', $verifiedAt);
    $pdo->prepare('UPDATE user_contact_emails SET removed_at=CURRENT_TIMESTAMP(6), verified_email_canonical=NULL WHERE id=:id')
        ->execute([':id' => $contact['id']]);
    $cases[$removed] = 'UNKNOWN';
    $tagged = 'first.last+' . $suffix . '@example.test';
    fc_contact_email_create($pdo, $userId, $tagged, 'USER', false, 'VERIFIED', $verifiedAt);
    $cases[$tagged] = 'KNOWN_ACCOUNT';
    $cases['firstlast+' . $suffix . '@example.test'] = 'UNKNOWN';
    $cases['first.last.' . $suffix . '@example.test'] = 'UNKNOWN';
    foreach (['GOOGLE', 'APPLE', 'MICROSOFT'] as $provider) {
        foreach ([true, false, null] as $verified) {
            $label = $verified === null ? 'unknown' : ($verified ? 'true' : 'false');
            $email = strtolower($provider) . '.' . $label . '.' . $suffix . '@example.test';
            $identity = [
                'provider_key' => $provider, 'issuer' => 'https://presence.example.test/' . strtolower($provider),
                'email_at_provider' => $email, 'provider_email_verified' => $verified,
                'email_verification_observed_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ];
            if ($provider === 'MICROSOFT') {
                $identity['provider_tenant_id'] = 'presence-' . $suffix;
                $identity['provider_object_id'] = $label . '-' . $suffix;
            } else {
                $identity['provider_subject'] = $label . '-' . $suffix;
            }
            fc_auth_identity_create($pdo, $userId, $identity);
            $cases[$email] = 'UNKNOWN';
        }
    }
    foreach (['absent.' . $suffix . '@example.test', '', '   ', "' OR 1=1 --"] as $email) {
        $cases[$email] = 'UNKNOWN';
    }
    $fingerprint = static function (PDO $db): array {
        $result = [];
        foreach (['users', 'user_contact_emails', 'user_auth_identities', 'user_sessions',
            'auth_transactions', 'audit_events', 'auth_invitation_continuations',
            'auth_invitation_admission_claims', 'email_magic_link_challenges',
            'crew_memberships', 'challenge_participations', 'security_rate_limit_buckets'] as $table) {
            $rows = array_map(static fn (array $row): string => serialize($row), $db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC));
            sort($rows, SORT_STRING);
            $result[$table] = hash('sha256', serialize($rows));
        }
        return $result;
    };
    $before = $fingerprint($pdo);
    $sessionBefore = $_SESSION ?? [];
    ob_start();
    try {
        foreach ($cases as $email => $expected) {
            fc_presence_assert(fc_auth_account_presence_for_email($pdo, $email) === $expected, 'Unexpected fixture classification.');
        }
    } finally { $output = (string) ob_get_clean(); }
    fc_presence_assert($output === '', 'Private lookup emitted account data.');
    fc_presence_assert($pdo->inTransaction(), 'Lookup completed the caller transaction.');
    fc_presence_assert($before === $fingerprint($pdo), 'Lookup mutated application data.');
    fc_presence_assert($sessionBefore === ($_SESSION ?? []), 'Lookup changed session state.');
    $pdo->rollBack();
    echo "Private Auth account-presence database proof: PASS\n";
    echo '- ' . count($cases) . " canonical ownership, status, provider-claim, and input cases: PASS\n";
    echo "- scalar-only result / unchanged tables and session / fixture rollback: PASS\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
