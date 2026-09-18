<?php

declare(strict_types=1);

require_once __DIR__ . '/email_magic_link.php';

const FC_EMAIL_IDENTITY_LINK_TTL_SECONDS = 600;
const FC_EMAIL_IDENTITY_LINK_REQUEST_MESSAGE =
    'If that email can be added, a confirmation link will arrive shortly.';

/** A fixed, short-lived navigation preference; never linking authority. */
function fc_email_identity_link_after_login(string $destination): string
{
    $until = $_SESSION['fitcrew_email_link_setup_until'] ?? 0;
    unset($_SESSION['fitcrew_email_link_setup_until']);
    return $destination === '/app.php' && is_int($until) && $until > time()
        ? '/auth/email/link.php'
        : $destination;
}

function fc_email_request_require_acknowledgement(string $mode = 'signin'): void
{
    $_SESSION['fitcrew_email_request_ack'] = $mode === 'link' ? 'link' : 'signin';
}

/** Resolve current authority from SQL, including recent authentication. */
function fc_email_identity_link_session(PDO $pdo, string $rawSessionId, bool $forUpdate = false): array
{
    if ($rawSessionId === '') {
        throw new DomainException('email_link_recent_signin_required');
    }
    if ($forUpdate && !$pdo->inTransaction()) {
        throw new LogicException('Link-session locking requires an active transaction.');
    }
    $query = $pdo->prepare(
        'SELECT s.user_id, s.auth_identity_id, u.public_id ' .
        'FROM user_sessions s JOIN users u ON u.id = s.user_id ' .
        'JOIN user_auth_identities i ON i.id = s.auth_identity_id AND i.user_id = s.user_id ' .
        'WHERE s.session_id_hash = :session_hash AND s.revoked_at IS NULL ' .
        'AND s.idle_expires_at > CURRENT_TIMESTAMP(6) AND s.absolute_expires_at > CURRENT_TIMESTAMP(6) ' .
        'AND s.created_at >= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 10 MINUTE) ' .
        "AND u.account_status = 'ACTIVE' AND i.identity_status = 'ACTIVE' LIMIT 1" .
        ($forUpdate ? ' FOR UPDATE' : '')
    );
    $query->execute([':session_hash' => fc_session_id_hash($rawSessionId)]);
    $session = $query->fetch(PDO::FETCH_ASSOC);
    if ($session === false) {
        throw new DomainException('email_link_recent_signin_required');
    }
    return $session;
}

/** @return array{id:int,token:string,email_subject:string} */
function fc_email_identity_link_issue(PDO $pdo, string $email, string $rawSessionId, string $browserBinding): array
{
    $subject = fc_email_magic_link_subject($email);
    if ($browserBinding === '') {
        throw new DomainException('email_identity_link_invalid');
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $session = fc_email_identity_link_session($pdo, $rawSessionId, true);
        // At most one pending add-method challenge per mailbox/authenticated session.
        // It has a separate namespace from every ordinary LOGIN/invitation flow.
        $flowHash = fc_email_magic_link_flow_hash($subject, 'LINK_IDENTITY:' . fc_session_id_hash($rawSessionId));
        $old = fc_email_magic_link_active_challenge_by_flow($pdo, $flowHash, true);
        if ($old !== null) {
            fc_email_magic_link_replace_challenge($pdo, (int) $old['id']);
            fc_auth_transaction_retire_unused($pdo, (int) $old['auth_transaction_id']);
        }
        $token = fc_email_magic_link_token();
        $transaction = fc_auth_transaction_create(
            $pdo, 'LINK_IDENTITY', 'EMAIL', (int) $session['user_id'],
            $token, $browserBinding, 'APP_HOME', $rawSessionId, null,
            FC_EMAIL_IDENTITY_LINK_TTL_SECONDS
        );
        // EMAIL LINK_IDENTITY uses nonce_hash to bind the initiating authenticated
        // session in addition to the browser binding and expected_user_id.
        $insert = $pdo->prepare(
            'INSERT INTO email_magic_link_challenges ' .
            '(public_id,auth_transaction_id,issuer,email_subject,token_hash,flow_key_hash,active_flow_key_hash,expires_at) ' .
            'VALUES (:public_id,:transaction_id,:issuer,:email,:token_hash,:flow_hash,:active_hash,:expires_at)'
        );
        $insert->execute([
            ':public_id' => fc_new_public_id(), ':transaction_id' => $transaction['id'],
            ':issuer' => FC_EMAIL_MAGIC_LINK_ISSUER, ':email' => $subject,
            ':token_hash' => fc_secret_evidence_hash($token), ':flow_hash' => $flowHash,
            ':active_hash' => $flowHash, ':expires_at' => $transaction['expires_at'],
        ]);
        $id = (int) $pdo->lastInsertId();
        if ($ownsTransaction) $pdo->commit();
        return ['id' => $id, 'token' => $token, 'email_subject' => $subject];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function fc_email_identity_link_request(
    PDO $pdo, string $email, string $rawSessionId, string $browserBinding,
    string $networkEvidence, ?callable $mailer = null
): bool {
    fc_email_identity_link_session($pdo, $rawSessionId);
    $network = fc_rate_limit_consume($pdo, 'auth.email_magic.request.network',
        $networkEvidence !== '' ? $networkEvidence : 'browser:' . $browserBinding, 20, 900);
    try { $subject = fc_email_magic_link_subject($email); }
    catch (InvalidArgumentException) { return false; }
    $mailbox = fc_rate_limit_consume($pdo, 'auth.email_magic.request.email', $subject, 5, 900);
    if (!$network['allowed'] || !$mailbox['allowed']) return false;

    $challenge = fc_email_identity_link_issue($pdo, $subject, $rawSessionId, $browserBinding);
    $url = rtrim((string) fc_config()['url'], '/') . '/auth/email/link-confirm.php#token=' . $challenge['token'];
    $instructions = 'You asked to add email sign-in to your existing FitCrew account. ' .
        'Open this link in the same browser where you made that request, while still signed in. ' .
        'Then explicitly confirm Add email sign-in. The link expires within 10 minutes and requires a recent sign-in. ' .
        'Opening it alone does not add a sign-in method. If you did not request this, do not continue.';
    $safeUrl = fc_e($url);
    $message = [
        'to' => $subject, 'from_name' => 'FitCrew Challenge',
        'subject' => 'Confirm adding email sign-in to FitCrew',
        'text_body' => $instructions . "\n\n" . $url,
        'html_body' => '<!doctype html><html><body style="font-family:Arial,sans-serif;line-height:1.6">' .
            '<h1>Add email sign-in</h1><p>' . fc_e($instructions) . '</p>' .
            '<p><a href="' . $safeUrl . '">Review adding email sign-in</a></p>' .
            '<p>If the button does not work, open:<br>' . $safeUrl . '</p></body></html>',
        'tag' => 'email-identity-link',
    ];
    $mailer ??= static fn (array $outbound): array => fc_mail_send($outbound);
    try {
        $result = $mailer($message);
        if (!($result['accepted'] ?? false)) {
            fc_email_magic_link_invalidate_after_delivery_failure($pdo, $challenge['id']);
            return false;
        }
        return true;
    } catch (Throwable $error) {
        fc_email_magic_link_invalidate_after_delivery_failure($pdo, $challenge['id']);
        throw $error;
    } finally {
        unset($challenge['token'], $url, $message);
    }
}

/** Add a method only to the current authenticated user. Never create/merge users. */
function fc_email_identity_link_complete(PDO $pdo, string $token, string $rawSessionId, string $browserBinding): void
{
    if (!fc_email_magic_link_token_valid_shape($token) || $browserBinding === '') {
        throw new DomainException('email_identity_link_invalid');
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $session = fc_email_identity_link_session($pdo, $rawSessionId, true);
        $query = $pdo->prepare(
            'SELECT c.*, t.public_id AS transaction_public_id, t.nonce_hash ' .
            'FROM email_magic_link_challenges c JOIN auth_transactions t ON t.id=c.auth_transaction_id ' .
            "WHERE c.token_hash=:token_hash AND c.issuer=:issuer AND c.challenge_status='ISSUED' " .
            'AND c.consumed_at IS NULL AND c.expires_at>CURRENT_TIMESTAMP(6) ' .
            "AND t.intent='LINK_IDENTITY' AND t.expected_provider='EMAIL' AND t.expected_user_id=:user_id " .
            'AND t.state_hash=:state_hash AND t.browser_session_binding_hash=:browser_hash ' .
            'AND t.nonce_hash=:session_hash AND t.consumed_at IS NULL AND t.expires_at>CURRENT_TIMESTAMP(6) ' .
            "AND t.post_auth_destination_key='APP_HOME' LIMIT 1 FOR UPDATE"
        );
        $query->execute([
            ':token_hash' => fc_secret_evidence_hash($token), ':issuer' => FC_EMAIL_MAGIC_LINK_ISSUER,
            ':user_id' => (int) $session['user_id'], ':state_hash' => fc_secret_evidence_hash($token),
            ':browser_hash' => fc_secret_evidence_hash($browserBinding),
            ':session_hash' => fc_secret_evidence_hash($rawSessionId),
        ]);
        $challenge = $query->fetch(PDO::FETCH_ASSOC);
        if ($challenge === false) throw new DomainException('email_identity_link_invalid');
        $userId = (int) $session['user_id'];
        $subject = (string) $challenge['email_subject'];
        fc_auth_identity_assert_link_target($pdo, 'EMAIL', FC_EMAIL_MAGIC_LINK_ISSUER, $subject, $userId);
        $owner = fc_contact_email_find_verified_owner($pdo, $subject, true);
        if ($owner !== null && (int) $owner['user_id'] !== $userId) {
            throw new DomainException('account_reconciliation_required');
        }
        foreach (fc_auth_identity_email_evidence_owners($pdo, $subject) as $evidence) {
            if ((int) $evidence['user_id'] !== $userId) {
                throw new DomainException('account_reconciliation_required');
            }
        }
        $identity = fc_auth_identity_find_oidc($pdo, 'EMAIL', FC_EMAIL_MAGIC_LINK_ISSUER, $subject, true);
        if ($identity !== null && (string) $identity['identity_status'] !== 'ACTIVE') {
            throw new DomainException('email_identity_link_unavailable');
        }
        if ($identity === null) {
            fc_auth_identity_create($pdo, $userId, [
                'provider_key' => 'EMAIL', 'issuer' => FC_EMAIL_MAGIC_LINK_ISSUER,
                'provider_subject' => $subject, 'email_at_provider' => $subject,
                'provider_email_verified' => 1,
                'email_verification_observed_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
                'identity_status' => 'ACTIVE',
            ]);
        }
        fc_email_magic_link_ensure_verified_contact($pdo, $userId, $subject);
        $consume = $pdo->prepare(
            "UPDATE email_magic_link_challenges SET challenge_status='CONSUMED', consumed_at=CURRENT_TIMESTAMP(6), " .
            "active_flow_key_hash=NULL WHERE id=:id AND challenge_status='ISSUED' AND consumed_at IS NULL"
        );
        $consume->execute([':id' => $challenge['id']]);
        if ($consume->rowCount() !== 1 || !fc_auth_transaction_consume(
            $pdo, (string) $challenge['transaction_public_id'], 'LINK_IDENTITY', 'EMAIL',
            $token, $browserBinding, $userId
        )) throw new DomainException('email_identity_link_invalid');
        fc_audit_event_write($pdo, [
            'actor_user_id' => $userId, 'event_type' => 'EMAIL_IDENTITY_LINKED',
            'target_type' => 'USER', 'target_id' => (string) $session['public_id'],
            'outcome' => 'SUCCESS', 'metadata' => ['provider' => 'EMAIL'],
        ]);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && (string) $error->getCode() === '23000'
            && (int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('account_reconciliation_required', 0, $error);
        }
        throw $error;
    }
}
