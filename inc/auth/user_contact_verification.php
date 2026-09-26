<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/mail/mail.php';

/** No claimed provider email, EMAIL subject, or canonical verified owner may cross accounts. */
function fc_user_contact_require_available(PDO $pdo, int $targetId, string $email): void
{
    $owner = fc_contact_email_find_verified_owner($pdo, $email, true);
    if ($owner !== null && (int) $owner['user_id'] !== $targetId) throw new DomainException('account_reconciliation_required');
    $q = fc_user_ops_query($pdo,
        "SELECT user_id FROM user_auth_identities WHERE
         (provider_key='EMAIL' AND provider_subject=?) OR LOWER(TRIM(email_at_provider))=? FOR UPDATE", [$email, $email]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if ((int) $id !== $targetId) throw new DomainException('account_reconciliation_required');
    }
}

/** Internal; caller holds actor/target locks and owns transaction. */
function fc_user_contact_issue_locked(PDO $pdo, array $actor, array $target, string $email, string $reason): array
{
    if (!$pdo->inTransaction() || !fc_user_ops_permitted($actor, $target, 'replacement_contact')) {
        throw new DomainException('user_operation_denied');
    }
    if ($target['account_status'] !== 'ACTIVE') throw new DomainException('active_contact_target_required');
    fc_user_contact_require_available($pdo, (int) $target['id'], $email);
    $recent = fc_user_ops_query($pdo,
        'SELECT id FROM auth_contact_verifications WHERE target_user_id=? AND created_at>UTC_TIMESTAMP(6)-INTERVAL 60 SECOND LIMIT 1 FOR UPDATE',
        [(int) $target['id']])->fetchColumn();
    if ($recent !== false) throw new DomainException('contact_verification_rate_limited');
    fc_user_ops_query($pdo,
        "UPDATE auth_contact_verifications SET status='REPLACED' WHERE target_user_id=? AND status IN ('PENDING_SEND','ISSUED')",
        [(int) $target['id']]);
    $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $public = fc_new_public_id();
    fc_user_ops_query($pdo,
        "INSERT INTO auth_contact_verifications
         (public_id,actor_user_id,target_user_id,email_canonical,token_hash,reason,target_updated_at,target_role,expires_at)
         VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)+INTERVAL 15 MINUTE)",
        [$public, (int) $actor['id'], (int) $target['id'], $email, hash('sha256', $raw), $reason, $target['updated_at'], $target['platform_role_code']]);
    return ['verification_public_id' => $public, 'raw_token' => $raw];
}

function fc_user_contact_message(string $email, string $rawToken): array
{
    $origin = rtrim((string) fc_config()['url'], '/');
    $scheme = parse_url($origin, PHP_URL_SCHEME);
    if ($scheme !== 'https' && fc_config()['env'] === 'production') throw new RuntimeException('HTTPS required.');
    $url = $origin . '/auth/contact/confirm.php#token=' . rawurlencode($rawToken);
    $text = "A FitCrew administrator requested that this email be added as a verified contact for an existing FitCrew account.\n\n"
        . "Only continue if this is your account and you expected this request. This does not sign you in or change your primary contact.\n\n"
        . $url . "\n\nThe link expires in 15 minutes. If unexpected, ignore it.\n\nFitCrew Challenge";
    return ['to' => $email, 'from_name' => 'FitCrew Challenge', 'subject' => 'Confirm your FitCrew contact email',
        'text_body' => $text, 'html_body' => '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>',
        'tag' => 'user-contact-verification'];
}

/** Internal delivery; success means transport acceptance, never mailbox verification. */
function fc_user_contact_deliver(PDO $pdo, string $public, string $raw, string $email): string
{
    $accepted = false;
    try {
        $result = fc_mail_send(fc_user_contact_message($email, $raw));
        $accepted = ($result['accepted'] ?? false) === true;
    } catch (Throwable) {
        // No transport exception details or message bodies enter application audit.
    } finally {
        unset($raw);
    }
    fc_user_ops_begin($pdo);
    try {
        $v = fc_user_ops_query($pdo, 'SELECT * FROM auth_contact_verifications WHERE public_id=? FOR UPDATE', [$public])->fetch(PDO::FETCH_ASSOC);
        if ($v === false || $v['status'] !== 'PENDING_SEND') {
            $pdo->commit();
            return 'UNAVAILABLE';
        }
        $status = $accepted ? 'ISSUED' : 'DELIVERY_FAILED';
        fc_user_ops_query($pdo, 'UPDATE auth_contact_verifications SET status=? WHERE id=?', [$status, (int) $v['id']]);
        fc_user_ops_audit($pdo, (int) $v['actor_user_id'], (int) $v['target_user_id'],
            'USER_CONTACT_VERIFICATION_DELIVERY', $v['reason'], ['delivery' => $accepted ? 'ACCEPTED' : 'FAILED', 'verification_public_id' => $public],
            $accepted ? 'SUCCESS' : 'FAILURE');
        $pdo->commit();
        return $accepted ? 'ACCEPTED' : 'FAILED';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // A PENDING_SEND record cannot complete. Do not retry a send on an unknown outcome.
        return 'UNKNOWN';
    }
}

/** Mailbox-only completion. It never creates a session or an authentication identity. */
function fc_auth_user_contact_verification_complete(PDO $pdo, string $rawToken): array
{
    if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/', $rawToken)) throw new DomainException('contact_verification_invalid');
    fc_user_ops_begin($pdo);
    try {
        $hint = fc_user_ops_query($pdo, 'SELECT actor_user_id,target_user_id FROM auth_contact_verifications WHERE token_hash=?',
            [hash('sha256', $rawToken)])->fetch(PDO::FETCH_ASSOC);
        if ($hint === false) throw new DomainException('contact_verification_invalid');
        $users = fc_user_ops_lock_users($pdo, [(int) $hint['actor_user_id'], (int) $hint['target_user_id']]);
        $actor = $users[(int) $hint['actor_user_id']]; $target = $users[(int) $hint['target_user_id']];
        $v = fc_user_ops_query($pdo,
            'SELECT *,expires_at>UTC_TIMESTAMP(6) AS unexpired FROM auth_contact_verifications WHERE token_hash=? FOR UPDATE',
            [hash('sha256', $rawToken)])->fetch(PDO::FETCH_ASSOC);
        if ($v === false || !fc_user_ops_permitted($actor, $target, 'replacement_contact')
            || $target['account_status'] !== 'ACTIVE') throw new DomainException('contact_verification_invalid');
        if ($v['status'] === 'CONSUMED') {
            $pdo->commit();
            return ['verified' => true, 'replayed' => true];
        }
        if ($v['status'] !== 'ISSUED' || $v['expires_at'] <= (string) $pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn()
            || $v['target_updated_at'] !== $target['updated_at'] || $v['target_role'] !== $target['platform_role_code']) {
            throw new DomainException('contact_verification_invalid');
        }
        fc_user_contact_require_available($pdo, (int) $target['id'], $v['email_canonical']);
        $existing = fc_user_ops_query($pdo,
            'SELECT id,verification_status FROM user_contact_emails WHERE user_id=? AND email_canonical=? AND removed_at IS NULL FOR UPDATE',
            [(int) $target['id'], $v['email_canonical']])->fetch(PDO::FETCH_ASSOC);
        if ($existing === false) {
            fc_contact_email_create($pdo, (int) $target['id'], $v['email_canonical'], 'ADMIN_MAILBOX_PROOF', false, 'VERIFIED',
                (string) $pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn());
        } elseif ($existing['verification_status'] !== 'VERIFIED') {
            fc_user_ops_query($pdo,
                "UPDATE user_contact_emails SET verification_status='VERIFIED',verified_at=UTC_TIMESTAMP(6),
                 verified_email_canonical=email_canonical,source_key='ADMIN_MAILBOX_PROOF' WHERE id=?",
                [(int) $existing['id']]);
        }
        if ($v['expires_at'] <= (string) $pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn()) throw new DomainException('contact_verification_invalid');
        fc_user_ops_query($pdo, "UPDATE auth_contact_verifications SET status='CONSUMED',consumed_at=UTC_TIMESTAMP(6) WHERE id=?", [(int) $v['id']]);
        fc_user_ops_query($pdo, 'UPDATE users SET updated_at=UTC_TIMESTAMP(6) WHERE id=?', [(int) $target['id']]);
        fc_user_ops_audit($pdo, (int) $actor['id'], (int) $target['id'], 'USER_CONTACT_VERIFIED', $v['reason'], [
            'before' => ['verification_status' => $existing === false ? 'ABSENT' : $existing['verification_status']],
            'after' => ['email' => $v['email_canonical'], 'verification_status' => 'VERIFIED'],
            'method' => 'mailbox_confirmation', 'verification_public_id' => $v['public_id'],
        ]);
        $pdo->commit();
        return ['verified' => true, 'replayed' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (($e instanceof PDOException && (string) $e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062)
            || ($e instanceof DomainException && $e->getMessage() === 'canonical_verified_email_conflict')) {
            $e = new DomainException('account_reconciliation_required', 0, $e);
        }
        if (isset($v) && is_array($v)) {
            fc_user_ops_audit($pdo, (int) $v['actor_user_id'], (int) $v['target_user_id'], 'USER_CONTACT_VERIFICATION_REJECTED', $v['reason'],
                ['verification_public_id' => $v['public_id'], 'failure' => $e instanceof DomainException ? $e->getMessage() : 'verification_failed'], $e instanceof DomainException ? 'DENIED' : 'FAILURE');
        }
        throw $e;
    }
}
