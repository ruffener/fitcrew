<?php

declare(strict_types=1);

require_once __DIR__ . '/email_magic_link.php';

const FC_AUTH_INVITATION_EMAIL_SESSION_KEY = 'fitcrew_invitation_email_proof';
const FC_AUTH_INVITATION_EMAIL_REVIEW_SECONDS = 1800;

/** Read-only Website dependency. Never accept a caller-supplied recipient email. */
function fc_auth_invitation_email_invitation(PDO $pdo, string $publicId, int $generation, bool $lock): array
{
    if ($lock && !$pdo->inTransaction()) throw new LogicException('Invitation proof lock requires a transaction.');
    if (fc_auth_crew_invitation_product_snapshot($pdo, $publicId, $generation, $lock) === null) {
        throw new DomainException('invitation_email_invalid');
    }
    $q = $pdo->prepare('SELECT public_id,resend_count,invited_email,token_hash,expires_at,challenge_id,transport_status,transport_driver,sent_at FROM crew_invitations WHERE public_id=? AND resend_count=? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$publicId, $generation]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row === false || $row['challenge_id'] === null) throw new DomainException('invitation_email_invalid');
    $row['email_canonical'] = fc_email_magic_link_subject((string) $row['invited_email']);
    return $row;
}

/**
 * Call during NEW issuance/resend, before the invitation email is sent.
 * Only the trusted server-side mail composer receives the raw invitation token.
 * Returns a fragment URL for the recipient email, never for the inviter.
 * Send only after the registration and Website issuance transaction commits.
 */
function fc_auth_invitation_email_register(PDO $pdo, string $invitationPublicId, int $generation, string $rawToken): string
{
    if (!fc_email_magic_link_token_valid_shape($rawToken)) throw new DomainException('invitation_email_invalid');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT fc_invitation_email_register');
    try {
        $inv = fc_auth_invitation_email_invitation($pdo, $invitationPublicId, $generation, true);
        if (!hash_equals((string) $inv['token_hash'], hash('sha256', $rawToken))
            || $inv['transport_status'] !== 'PENDING_SEND' || $inv['sent_at'] !== null) {
            throw new DomainException('invitation_email_not_new_issuance');
        }
        $q = $pdo->prepare('SELECT * FROM auth_invitation_email_proofs WHERE invitation_public_id=? AND invitation_generation=? FOR UPDATE');
        $q->execute([$invitationPublicId, $generation]);
        $prior = $q->fetch(PDO::FETCH_ASSOC);
        if ($prior !== false) {
            if ($prior['consumed_at'] !== null || !hash_equals($prior['token_hash'], $inv['token_hash'])
                || !hash_equals($prior['email_canonical'], $inv['email_canonical'])
                || $prior['expires_at'] !== $inv['expires_at']) {
                throw new DomainException('invitation_email_invalid');
            }
        } else {
            $pdo->prepare('INSERT INTO auth_invitation_email_proofs (public_id,invitation_public_id,invitation_generation,token_hash,email_canonical,expires_at) VALUES(?,?,?,?,?,?)')
                ->execute([fc_new_public_id(), $invitationPublicId, $generation, $inv['token_hash'], $inv['email_canonical'], $inv['expires_at']]);
        }
        if ($ownsTransaction) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT fc_invitation_email_register');
        // Secret URL for the recipient email only. Never return it to the inviter.
        return rtrim((string) fc_config()['url'], '/') . '/crew-invite.php#token=' . rawurlencode($rawToken);
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$ownsTransaction) {
            $pdo->exec('ROLLBACK TO SAVEPOINT fc_invitation_email_register');
            $pdo->exec('RELEASE SAVEPOINT fc_invitation_email_register');
        }
        throw $e;
    }
}

/** Revalidate the exact registered proof AND current Website authority. */
function fc_auth_invitation_email_read(PDO $pdo, string $proofPublicId, bool $lock = false): array
{
    $q = $pdo->prepare('SELECT * FROM auth_invitation_email_proofs WHERE public_id=? LIMIT 1');
    $q->execute([$proofPublicId]);
    $hint = $q->fetch(PDO::FETCH_ASSOC);
    if ($hint === false) throw new DomainException('invitation_email_invalid');
    // Always lock Website first, as its cancellation/resend/enrollment paths do.
    $inv = fc_auth_invitation_email_invitation($pdo, $hint['invitation_public_id'], (int) $hint['invitation_generation'], $lock);
    $q = $pdo->prepare('SELECT * FROM auth_invitation_email_proofs WHERE public_id=? AND consumed_at IS NULL AND expires_at>CURRENT_TIMESTAMP(6) LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$proofPublicId]);
    $proof = $q->fetch(PDO::FETCH_ASSOC);
    if ($proof === false || !hash_equals($proof['token_hash'], $inv['token_hash'])
        || !hash_equals($proof['email_canonical'], $inv['email_canonical'])
        || $proof['expires_at'] !== $inv['expires_at']
        || $inv['transport_status'] !== 'TRANSPORT_ACCEPTED' || $inv['transport_driver'] !== 'postmark' || $inv['sent_at'] === null) {
        throw new DomainException('invitation_email_invalid');
    }
    return $proof;
}

/**
 * Landing/review only: no SQL writes, no authentication, no consumption.
 * Website strips the fragment locally, then exchanges it by a protected POST.
 * This capture POST is review-only, NOT the participant's Accept action.
 * Never place this server session receipt in cookies, URLs, logs, or hidden fields.
 * @return array{proof_public_id:string,invitation_public_id:string,generation:int,email:string}
 */
function fc_auth_invitation_email_capture(PDO $pdo, string $rawToken, string $csrfToken): array
{
    if (!fc_is_post()) throw new DomainException('invitation_email_post_required');
    if (!fc_email_magic_link_completion_origin_valid($_SERVER['HTTP_ORIGIN'] ?? null)) throw new DomainException('invitation_email_origin_failed');
    if (!fc_validate_csrf($csrfToken)) throw new DomainException('invitation_email_csrf_failed');
    if (isset($_GET['token'])) throw new DomainException('invitation_email_query_token_forbidden');
    if (!fc_email_magic_link_token_valid_shape($rawToken)) throw new DomainException('invitation_email_invalid');
    $q = $pdo->prepare('SELECT public_id FROM auth_invitation_email_proofs WHERE token_hash=? LIMIT 1');
    $q->execute([hash('sha256', $rawToken)]);
    $id = $q->fetchColumn();
    if ($id === false) throw new DomainException('invitation_email_not_registered');
    $proof = fc_auth_invitation_email_read($pdo, (string) $id);
    $_SESSION[FC_AUTH_INVITATION_EMAIL_SESSION_KEY] = [
        'proof_public_id' => (string) $id,
        'browser_hash' => fc_secret_evidence_hash(fc_auth_browser_binding()),
        'expires_at' => min(time() + FC_AUTH_INVITATION_EMAIL_REVIEW_SECONDS, (new DateTimeImmutable($proof['expires_at'], new DateTimeZone('UTC')))->getTimestamp()),
    ];
    return fc_auth_invitation_email_context($pdo, (string) $id);
}

/** Recipient-only review data, available only after actual bearer capture. */
function fc_auth_invitation_email_context(PDO $pdo, ?string $proofPublicId = null): array
{
    $receipt = $_SESSION[FC_AUTH_INVITATION_EMAIL_SESSION_KEY] ?? null;
    $proofPublicId ??= is_array($receipt) ? (string) ($receipt['proof_public_id'] ?? '') : '';
    if (!is_array($receipt) || ($receipt['proof_public_id'] ?? '') !== $proofPublicId
        || (int) ($receipt['expires_at'] ?? 0) <= time()
        || !hash_equals((string) ($receipt['browser_hash'] ?? ''), fc_secret_evidence_hash(fc_auth_browser_binding()))) {
        throw new DomainException('invitation_email_review_expired');
    }
    $proof = fc_auth_invitation_email_read($pdo, $proofPublicId);
    return ['proof_public_id' => $proof['public_id'], 'invitation_public_id' => $proof['invitation_public_id'],
        'generation' => (int) $proof['invitation_generation'], 'email' => $proof['email_canonical']];
}

/**
 * Explicit Accept POST ONLY, inside Website's enrollment transaction.
 * Website validates acceptance/Rules/privacy and passes the exact reviewed IDs.
 * This service saves/rolls back its own SQL work on failure; NEVER commits.
 * Profile-required and all denials leave the proof unconsumed and create nothing.
 * On success Website enrolls result.user_id, commits, then redirects to /app.php.
 * Do not use a cached fc_current_user() result to choose the enrollment account.
 * @return array{user_id:int,session_record_id:int,new_account:bool,invitation_public_id:string,generation:int}
 */
function fc_auth_invitation_email_complete(
    PDO $pdo, string $proofPublicId, string $invitationPublicId, int $generation,
    string $csrfToken, ?string $displayName = null
): array {
    if (!$pdo->inTransaction()) throw new LogicException('Website must own the enrollment transaction.');
    if (!fc_is_post()) throw new DomainException('invitation_email_post_required');
    if (!fc_email_magic_link_completion_origin_valid($_SERVER['HTTP_ORIGIN'] ?? null)) throw new DomainException('invitation_email_origin_failed');
    if (!fc_validate_csrf($csrfToken)) throw new DomainException('invitation_email_csrf_failed');
    $context = fc_auth_invitation_email_context($pdo, $proofPublicId);
    if ($context['invitation_public_id'] !== $invitationPublicId || $context['generation'] !== $generation) {
        throw new DomainException('invitation_email_context_mismatch');
    }
    $pdo->exec('SAVEPOINT fc_invitation_email_complete');
    try {
        $proof = fc_auth_invitation_email_read($pdo, $proofPublicId, true);
        $admissionId = null;
        $account = fc_email_account_resolve_verified_mailbox($pdo, $proof['email_canonical'],
            static function () use ($pdo, $proof, $displayName, &$admissionId): void {
                if ($displayName === null || trim($displayName) === '') throw new DomainException('invitation_email_profile_required');
                if (mb_strlen(trim($displayName), 'UTF-8') > 120 || preg_match('/[\x00-\x1F\x7F]/u', $displayName)) {
                    throw new DomainException('invitation_email_profile_invalid');
                }
                // Reuse the canonical ONE new account per logical invitation claim.
                // This internal admission record causes no login redirect/provider chain.
                $pdo->prepare('INSERT INTO auth_invitation_continuations (public_id,purpose,invitation_public_id,invitation_generation,browser_session_binding_hash,expires_at) VALUES(?,?,?,?,?,?)')
                    ->execute([fc_new_public_id(), FC_AUTH_CREW_INVITATION_PURPOSE, $proof['invitation_public_id'], $proof['invitation_generation'], fc_secret_evidence_hash(fc_auth_browser_binding()), $proof['expires_at']]);
                $admissionId = (int) $pdo->lastInsertId();
                fc_auth_crew_invitation_admission_claim($pdo, $admissionId, $proof['invitation_public_id']);
            }, $displayName === null ? null : trim($displayName), 'CHALLENGE_INVITATION_EMAIL');
        $userId = (int) $account['user']['id'];
        // Never silently change an already authenticated account to the invite recipient.
        $q = $pdo->prepare("SELECT s.user_id FROM user_sessions s JOIN users u ON u.id=s.user_id JOIN user_auth_identities a ON a.id=s.auth_identity_id AND a.user_id=s.user_id WHERE s.session_id_hash=? AND s.revoked_at IS NULL AND s.idle_expires_at>CURRENT_TIMESTAMP(6) AND s.absolute_expires_at>CURRENT_TIMESTAMP(6) AND u.account_status='ACTIVE' AND a.identity_status='ACTIVE' LIMIT 1 FOR UPDATE");
        $q->execute([fc_session_id_hash(session_id())]);
        $currentUserId = $q->fetchColumn();
        if ($currentUserId !== false && (int) $currentUserId !== $userId) throw new DomainException('invitation_email_account_switch_required');
        if ($currentUserId !== false) fc_session_revoke($pdo, session_id(), 'invitation_email_reauthentication');
        $session = fc_establish_authenticated_session($pdo, $userId, (int) $account['identity']['id'], $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null);
        if ($admissionId !== null) {
            fc_auth_crew_invitation_continuation_mark_authenticated($pdo, $admissionId, $userId, (int) $session['id'], true);
            fc_auth_crew_invitation_admission_complete($pdo, $admissionId, $userId);
            $pdo->prepare("UPDATE auth_invitation_continuations SET continuation_status='CONSUMED',consumed_at=CURRENT_TIMESTAMP(6) WHERE id=? AND continuation_status='AUTHENTICATED'")->execute([$admissionId]);
        }
        $q = $pdo->prepare('UPDATE auth_invitation_email_proofs SET consumed_at=CURRENT_TIMESTAMP(6),user_id=?,session_record_id=? WHERE id=? AND consumed_at IS NULL');
        $q->execute([$userId, (int) $session['id'], (int) $proof['id']]);
        if ($q->rowCount() !== 1) throw new DomainException('invitation_email_invalid');
        foreach (['EMAIL_AUTH_SUCCESS' => ['USER', $account['user']['public_id']], 'SESSION_CREATED' => ['SESSION', (string) $session['id']]] as $event => $target) {
            fc_audit_event_write($pdo, ['actor_user_id' => $userId, 'event_type' => $event, 'target_type' => $target[0], 'target_id' => $target[1], 'outcome' => 'SUCCESS',
                'metadata' => ['provider' => 'EMAIL', 'proof_kind' => 'CHALLENGE_INVITATION', 'new_account' => $account['new_account']],
                'raw_client_evidence' => $_SERVER['REMOTE_ADDR'] ?? null]);
        }
        $pdo->exec('RELEASE SAVEPOINT fc_invitation_email_complete');
        return ['user_id' => $userId, 'session_record_id' => (int) $session['id'], 'new_account' => (bool) $account['new_account'],
            'invitation_public_id' => $invitationPublicId, 'generation' => $generation];
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK TO SAVEPOINT fc_invitation_email_complete');
        $pdo->exec('RELEASE SAVEPOINT fc_invitation_email_complete');
        throw $e;
    }
}

/** Call after Website rolls back a failed attempt; never log raw exception text. */
function fc_auth_invitation_email_audit_rejection(PDO $pdo, Throwable $failure): void
{
    if ($pdo->inTransaction()) throw new LogicException('Audit the rejection after rolling back enrollment.');
    $reason = in_array($failure->getMessage(), [
        'invitation_email_invalid', 'invitation_email_not_registered', 'invitation_email_not_new_issuance',
        'invitation_email_review_expired', 'invitation_email_post_required', 'invitation_email_origin_failed',
        'invitation_email_csrf_failed', 'invitation_email_context_mismatch', 'invitation_email_account_switch_required',
        'invitation_email_query_token_forbidden', 'invitation_email_profile_invalid',
        'account_reconciliation_required', 'canonical_verified_email_conflict', 'fitcrew_account_access_denied',
        'prelaunch_invitation_admission_claimed',
    ], true) ? $failure->getMessage() : 'invitation_email_failure';
    fc_audit_event_write($pdo, ['event_type' => 'EMAIL_AUTH_REJECTED', 'target_type' => 'AUTHENTICATION',
        'outcome' => 'DENIED', 'metadata' => ['provider' => 'EMAIL', 'proof_kind' => 'CHALLENGE_INVITATION', 'reason' => $reason]]);
}
