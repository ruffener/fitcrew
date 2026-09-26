<?php

declare(strict_types=1);

// Load after inc/bootstrap.php. No Admin surface dependencies.
require_once __DIR__ . '/user_contact_verification.php';

const FC_USER_OPERATION_LOCALES = ['en']; // Only the English application is shipped.
const FC_USER_OPERATION_EVENTS = [
    'profile' => 'USER_PROFILE_EDITED',
    'end_sessions' => 'USER_SESSIONS_ENDED',
    'suspend' => 'USER_ACCOUNT_SUSPENDED',
    'restore' => 'USER_ACCOUNT_RESTORED',
    'primary_contact' => 'USER_PRIMARY_CONTACT_SELECTED',
    'replacement_contact' => 'USER_CONTACT_VERIFICATION_INITIATED',
];

function fc_user_ops_query(PDO $pdo, string $sql, array $values = []): PDOStatement
{
    $q = $pdo->prepare($sql);
    $q->execute($values);
    return $q;
}

function fc_user_ops_begin(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('User operations own their transaction.');
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->beginTransaction();
}

/** Internal: user rows are locked in ascending order, before sessions/contacts. */
function fc_user_ops_lock_users(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids, SORT_NUMERIC);
    $rows = [];
    foreach ($ids as $id) {
        $row = fc_user_ops_query($pdo, 'SELECT * FROM users WHERE id = ? FOR UPDATE', [$id])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new DomainException('user_operation_unavailable');
        $rows[$id] = $row;
    }
    return $rows;
}

function fc_user_ops_permitted(array $actor, array $target, string $operation): bool
{
    if ($actor['account_status'] !== 'ACTIVE' || !isset(FC_USER_OPERATION_EVENTS[$operation])) return false;
    if (!in_array($target['account_status'], ['ACTIVE', 'SUSPENDED'], true)) return false;
    if (!in_array($target['platform_role_code'], ['USER', 'PLATFORM_ADMIN'], true)) return false;
    return $actor['platform_role_code'] === 'PLATFORM_SUPER_ADMIN'
        || ($actor['platform_role_code'] === 'PLATFORM_ADMIN'
            && $target['platform_role_code'] === 'USER'
            && in_array($operation, ['profile', 'end_sessions'], true));
}

/** Authority is resolved from the actual PHP session, never a caller-supplied actor. */
function fc_user_ops_context(PDO $pdo, string $targetPublicId): array
{
    if (!fc_public_id_is_valid($targetPublicId) || session_id() === '') {
        throw new DomainException('user_operation_denied');
    }
    $hash = fc_session_id_hash(session_id());
    $actorId = fc_user_ops_query($pdo, 'SELECT user_id FROM user_sessions WHERE session_id_hash = ?', [$hash])->fetchColumn();
    $targetId = fc_user_ops_query($pdo, 'SELECT id FROM users WHERE public_id = ?', [$targetPublicId])->fetchColumn();
    if ($actorId === false || $targetId === false) throw new DomainException('user_operation_denied');
    $users = fc_user_ops_lock_users($pdo, [(int) $actorId, (int) $targetId]);
    $session = fc_user_ops_query($pdo,
        "SELECT s.id,s.idle_expires_at,s.absolute_expires_at FROM user_sessions s JOIN user_auth_identities i ON i.id=s.auth_identity_id AND i.user_id=s.user_id
         WHERE s.session_id_hash=? AND s.user_id=? AND s.revoked_at IS NULL
         AND s.idle_expires_at>UTC_TIMESTAMP(6) AND s.absolute_expires_at>UTC_TIMESTAMP(6)
         AND i.identity_status='ACTIVE' FOR UPDATE", [$hash, (int) $actorId])->fetch(PDO::FETCH_ASSOC);
    $actor = $users[(int) $actorId];
    if ($session === false || min($session['idle_expires_at'], $session['absolute_expires_at']) <= (string) $pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn() || $actor['account_status'] !== 'ACTIVE'
        || !in_array($actor['platform_role_code'], ['PLATFORM_ADMIN', 'PLATFORM_SUPER_ADMIN'], true)) {
        throw new DomainException('user_operation_denied');
    }
    return [$actor, $users[(int) $targetId]];
}

/** Internal snapshot is never returned with raw session evidence. */
function fc_user_ops_state(PDO $pdo, array $target): array
{
    $contacts = fc_user_ops_query($pdo,
        'SELECT id,email_canonical,verification_status,is_primary_for_contact,verified_at,updated_at
         FROM user_contact_emails WHERE user_id=? AND removed_at IS NULL ORDER BY id FOR UPDATE',
        [(int) $target['id']])->fetchAll(PDO::FETCH_ASSOC);
    $sessions = fc_user_ops_query($pdo,
        'SELECT id,created_at,revoked_at FROM user_sessions WHERE user_id=? ORDER BY id FOR UPDATE',
        [(int) $target['id']])->fetchAll(PDO::FETCH_ASSOC);
    $profile = array_intersect_key($target, array_flip([
        'public_id', 'display_name', 'timezone', 'locale', 'account_status', 'platform_role_code', 'updated_at',
    ]));
    $revision = hash('sha256', json_encode([$profile, $contacts, $sessions], JSON_THROW_ON_ERROR));
    return ['profile' => $profile, 'contacts' => $contacts, 'revision' => $revision];
}

/** Read model for an editor; revision must be returned unchanged on submission. */
function fc_auth_user_operations_snapshot(PDO $pdo, string $targetPublicId): array
{
    fc_user_ops_begin($pdo);
    try {
        [$actor, $target] = fc_user_ops_context($pdo, $targetPublicId);
        $allowed = array_values(array_filter(array_keys(FC_USER_OPERATION_EVENTS),
            static fn (string $op): bool => fc_user_ops_permitted($actor, $target, $op)));
        if ($allowed === []) throw new DomainException('user_operation_denied');
        $state = fc_user_ops_state($pdo, $target);
        // Ordinary Admin cannot manage contacts, so receives no contact edit data.
        if (!in_array('primary_contact', $allowed, true)) unset($state['contacts']);
        $state['allowed_operations'] = $allowed;
        $state['supported_locales'] = FC_USER_OPERATION_LOCALES;
        $pdo->commit();
        return $state;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function fc_user_ops_text(mixed $value, int $max, string $field): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) throw new InvalidArgumentException('invalid_' . $field);
    $value = trim($value);
    if ($value === '' || preg_match_all('/./us', $value) > $max || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
        throw new InvalidArgumentException('invalid_' . $field);
    }
    return $value;
}

function fc_user_ops_fields(string $operation, array $fields): array
{
    $allowed = match ($operation) {
        'profile' => ['display_name', 'timezone', 'locale'],
        'primary_contact' => ['contact_id'],
        'replacement_contact' => ['email'],
        'end_sessions', 'suspend', 'restore' => [],
        default => throw new InvalidArgumentException('invalid_operation'),
    };
    if (array_diff(array_keys($fields), $allowed) !== []) throw new InvalidArgumentException('unexpected_fields');
    if ($operation === 'profile') {
        if ($fields === []) throw new InvalidArgumentException('profile_fields_required');
        if (array_key_exists('display_name', $fields)) $fields['display_name'] = fc_user_ops_text($fields['display_name'], 120, 'display_name');
        if (array_key_exists('timezone', $fields) && (!is_string($fields['timezone'])
            || !in_array($fields['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true))) {
            throw new InvalidArgumentException('invalid_timezone');
        }
        if (array_key_exists('locale', $fields) && !in_array($fields['locale'], FC_USER_OPERATION_LOCALES, true)) {
            throw new InvalidArgumentException('unsupported_locale');
        }
    } elseif ($operation === 'primary_contact') {
        if (!isset($fields['contact_id']) || !is_int($fields['contact_id']) || $fields['contact_id'] < 1) {
            throw new InvalidArgumentException('invalid_contact_id');
        }
    } elseif ($operation === 'replacement_contact') {
        if (!isset($fields['email']) || !is_string($fields['email'])) throw new InvalidArgumentException('invalid_email');
        $email = trim($fields['email']);
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new InvalidArgumentException('invalid_email');
        $fields['email'] = fc_contact_email_canonicalize($email);
    }
    ksort($fields);
    return $fields;
}

function fc_user_ops_audit(PDO $pdo, int $actor, int $target, string $event, string $reason, array $metadata, string $outcome = 'SUCCESS'): int
{
    return fc_audit_event_write($pdo, [
        'actor_user_id' => $actor, 'event_type' => $event, 'target_type' => 'USER',
        'target_id' => (string) $target, 'outcome' => $outcome,
        'metadata' => ['reason' => $reason] + $metadata,
    ])['id'];
}

/** Internal dispatcher. The six public wrappers below are the service contract. */
function fc_user_ops_mutate(PDO $pdo, string $targetPublicId, string $operation, array $fields, string $revision, string $requestKey, string $reason): array
{
    $fields = fc_user_ops_fields($operation, $fields);
    $reason = fc_user_ops_text($reason, 500, 'reason');
    if (!preg_match('/\A[a-f0-9]{64}\z/', $revision)) throw new InvalidArgumentException('invalid_revision');
    if (!preg_match('/\A[A-Za-z0-9_-]{16,80}\z/', $requestKey)) throw new InvalidArgumentException('invalid_request_key');
    $digest = hash('sha256', json_encode([$targetPublicId, $operation, $fields, $revision, $reason], JSON_THROW_ON_ERROR));
    $actor = $target = null;
    fc_user_ops_begin($pdo);
    try {
        [$actor, $target] = fc_user_ops_context($pdo, $targetPublicId);
        if (!fc_user_ops_permitted($actor, $target, $operation)) throw new DomainException('user_operation_denied');
        $prior = fc_user_ops_query($pdo,
            'SELECT request_digest,result_json FROM auth_user_operations WHERE actor_user_id=? AND request_key=? FOR UPDATE',
            [(int) $actor['id'], $requestKey])->fetch(PDO::FETCH_ASSOC);
        if ($prior !== false) {
            if (!hash_equals($prior['request_digest'], $digest)) throw new DomainException('idempotency_conflict');
            $result = json_decode($prior['result_json'], true, 512, JSON_THROW_ON_ERROR);
            $pdo->commit();
            return $result + ['replayed' => true];
        }
        $state = fc_user_ops_state($pdo, $target);
        if (!hash_equals($state['revision'], $revision)) throw new DomainException('stale_user_state');
        $before = $after = [];
        $extra = [];
        if ($operation === 'profile') {
            $before = array_intersect_key($target, $fields);
            $after = $fields;
            $sets = implode(',', array_map(static fn (string $key): string => $key . '=?', array_keys($fields)));
            fc_user_ops_query($pdo, 'UPDATE users SET ' . $sets . ',updated_at=UTC_TIMESTAMP(6) WHERE id=?', [...array_values($fields), (int) $target['id']]);
        } elseif ($operation === 'end_sessions') {
            $extra['revoked_sessions'] = fc_session_revoke_all_for_user($pdo, (int) $target['id'], 'administrator_ended_sessions');
        } elseif ($operation === 'suspend' || $operation === 'restore') {
            $expected = $operation === 'suspend' ? 'ACTIVE' : 'SUSPENDED';
            $next = $operation === 'suspend' ? 'SUSPENDED' : 'ACTIVE';
            if ($target['account_status'] !== $expected) throw new DomainException('invalid_account_transition');
            $before = ['account_status' => $expected]; $after = ['account_status' => $next];
            fc_user_ops_query($pdo,
                'UPDATE users SET account_status=?,suspended_at=' . ($next === 'SUSPENDED' ? 'UTC_TIMESTAMP(6)' : 'NULL') . ',updated_at=UTC_TIMESTAMP(6) WHERE id=?',
                [$next, (int) $target['id']]);
            // Restore defensively retires any remaining records too; never revives a session.
            $extra['revoked_sessions'] = fc_session_revoke_all_for_user($pdo, (int) $target['id'], 'administrator_' . $operation);
        } elseif ($operation === 'primary_contact') {
            $chosen = null; $primary = null;
            foreach ($state['contacts'] as $contact) {
                if ((int) $contact['is_primary_for_contact'] === 1) $primary = $contact['email_canonical'];
                if ((int) $contact['id'] === $fields['contact_id'] && $contact['verification_status'] === 'VERIFIED') $chosen = $contact;
            }
            if ($chosen === null) throw new DomainException('verified_owned_contact_required');
            $before = ['primary_contact' => $primary]; $after = ['primary_contact' => $chosen['email_canonical']];
            fc_contact_email_set_primary($pdo, (int) $target['id'], $fields['contact_id']);
        } else {
            $extra = fc_user_contact_issue_locked($pdo, $actor, $target, $fields['email'], $reason);
            $after = ['verification_email' => $fields['email'], 'verification_status' => 'PENDING'];
        }
        fc_user_ops_query($pdo, 'UPDATE users SET updated_at=UTC_TIMESTAMP(6) WHERE id=?', [(int) $target['id']]);
        // Recheck expiry after all lock waits and before committing any success.
        fc_user_ops_context($pdo, $targetPublicId);
        $audit = fc_user_ops_audit($pdo, (int) $actor['id'], (int) $target['id'], FC_USER_OPERATION_EVENTS[$operation], $reason,
            ['before' => $before, 'after' => $after] + array_diff_key($extra, ['raw_token' => true]));
        $result = ['operation' => $operation, 'target_public_id' => $targetPublicId, 'audit_id' => $audit]
            + array_diff_key($extra, ['raw_token' => true]);
        fc_user_ops_query($pdo,
            'INSERT INTO auth_user_operations (actor_user_id,target_user_id,request_key,request_digest,operation_key,result_json) VALUES (?,?,?,?,?,?)',
            [(int) $actor['id'], (int) $target['id'], $requestKey, $digest, $operation, json_encode($result, JSON_THROW_ON_ERROR)]);
        if ($operation === 'replacement_contact') {
            fc_user_ops_query($pdo,
                'UPDATE auth_contact_verifications v JOIN users u ON u.id=v.target_user_id SET v.target_updated_at=u.updated_at WHERE v.public_id=?',
                [$extra['verification_public_id']]);
        }
        $pdo->commit();
        // Raw token stays within this Auth-owned call and is never returned to Admin.
        if ($operation === 'replacement_contact') {
            $result['delivery'] = fc_user_contact_deliver($pdo, $extra['verification_public_id'], $extra['raw_token'], $fields['email']);
        }
        return $result + ['replayed' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($actor !== null && $target !== null) {
            fc_user_ops_audit($pdo, (int) $actor['id'], (int) $target['id'], 'USER_OPERATION_REJECTED', $reason,
                ['operation' => $operation, 'failure' => $e instanceof DomainException ? $e->getMessage() : 'operation_failed'], $e instanceof DomainException ? 'DENIED' : 'FAILURE');
        }
        throw $e;
    }
}

function fc_auth_user_profile_edit(PDO $pdo, string $target, array $fields, string $revision, string $requestKey, string $reason): array
{ return fc_user_ops_mutate($pdo, $target, 'profile', $fields, $revision, $requestKey, $reason); }
function fc_auth_user_sessions_end(PDO $pdo, string $target, string $revision, string $requestKey, string $reason): array
{ return fc_user_ops_mutate($pdo, $target, 'end_sessions', [], $revision, $requestKey, $reason); }
function fc_auth_user_suspend(PDO $pdo, string $target, string $revision, string $requestKey, string $reason): array
{ return fc_user_ops_mutate($pdo, $target, 'suspend', [], $revision, $requestKey, $reason); }
function fc_auth_user_restore(PDO $pdo, string $target, string $revision, string $requestKey, string $reason): array
{ return fc_user_ops_mutate($pdo, $target, 'restore', [], $revision, $requestKey, $reason); }
function fc_auth_user_primary_contact_select(PDO $pdo, string $target, int $contactId, string $revision, string $requestKey, string $reason): array
{ return fc_user_ops_mutate($pdo, $target, 'primary_contact', ['contact_id' => $contactId], $revision, $requestKey, $reason); }
function fc_auth_user_replacement_contact_initiate(PDO $pdo, string $target, string $email, string $revision, string $requestKey, string $reason): array
{ return fc_user_ops_mutate($pdo, $target, 'replacement_contact', ['email' => $email], $revision, $requestKey, $reason); }
