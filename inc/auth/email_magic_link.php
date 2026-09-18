<?php

declare(strict_types=1);

require_once __DIR__ . '/invitation_continuations.php';
require_once fc_path('inc/mail/mail.php');

const FC_EMAIL_MAGIC_LINK_ISSUER = 'https://fitcrewchallenge.com/auth/email';
const FC_EMAIL_MAGIC_LINK_TTL_SECONDS = 900;
const FC_EMAIL_MAGIC_LINK_REQUEST_MESSAGE =
    'If that email can be used, a FitCrew sign-in link will arrive shortly.';

const FC_EMAIL_MAGIC_LINK_RETRY_MESSAGE =
    'Your sign-in page was refreshed. Please request your email sign-in link again.';

function fc_email_request_require_acknowledgement(string $mode = 'signin'): void
{
    $_SESSION['fitcrew_email_request_ack'] = $mode === 'retry' ? 'retry' : 'signin';
}

function fc_email_magic_link_subject(string $email): string
{
    $email = trim($email);
    if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Email address is invalid.');
    }

    return function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);
}

function fc_email_magic_link_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function fc_email_magic_link_token_valid_shape(string $token): bool
{
    if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
        return false;
    }

    $decoded = base64_decode(strtr($token, '-_', '+/') . '=', true);
    return is_string($decoded) && strlen($decoded) === 32;
}

function fc_email_magic_link_expected_origin(): string
{
    $parts = parse_url((string) fc_config()['url']);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException('APP_URL must be absolute for email authentication.');
    }

    $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
    if (isset($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }

    return $origin;
}

function fc_email_magic_link_request_origin_valid(?string $origin): bool
{
    return is_string($origin)
        && $origin !== ''
        && hash_equals(fc_email_magic_link_expected_origin(), strtolower(rtrim($origin, '/')));
}

/**
 * Email completion is protected by a session-bound CSRF token created on the
 * isolated confirmation page. Some legitimate browsers or hosting paths omit
 * Origin on a same-origin form POST, so absence is permitted here only. An
 * explicitly supplied origin must still be the canonical FitCrew origin;
 * opaque ("null") and foreign origins remain rejected.
 */
function fc_email_magic_link_completion_origin_valid(?string $origin): bool
{
    if ($origin === null || trim($origin) === '') {
        return true;
    }

    return fc_email_magic_link_request_origin_valid($origin);
}

/** @return array<string,mixed> */
function fc_email_magic_link_message(string $recipientEmail, string $url): array
{
    $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = "Use this one-time link to sign in to FitCrew Challenge:\n\n" . $url . "\n\n" .
        "The link expires in 15 minutes and may be opened in any browser or device.\n" .
        "Opening the link does not sign you in. You must explicitly continue on the confirmation page.\n\n" .
        "If you did not request this link, you can ignore this email.\n\nFitCrew Challenge";
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#13233a;line-height:1.5">' .
        '<div style="max-width:620px;margin:0 auto;padding:24px">' .
        '<h1 style="font-size:24px;margin:0 0 16px">Your FitCrew sign-in link</h1>' .
        '<p>Use this one-time link to continue to FitCrew Challenge.</p>' .
        '<p style="margin:28px 0"><a href="' . $safeUrl . '" style="background:#1e40af;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;display:inline-block;font-weight:700">Review Sign-In</a></p>' .
        '<p style="font-size:14px;color:#5e6878">The link expires in 15 minutes and may be opened in any browser or device. Opening it does not sign you in; you must explicitly continue.</p>' .
        '<p style="font-size:14px;color:#5e6878">If the button does not work, open:<br>' . $safeUrl . '</p>' .
        '<p style="font-size:14px;color:#5e6878">If you did not request this link, you can ignore this email.</p>' .
        '</div></body></html>';

    return [
        'to' => $recipientEmail,
        'from_name' => 'FitCrew Challenge',
        'subject' => 'Your FitCrew sign-in link',
        'text_body' => $text,
        'html_body' => $html,
        'tag' => 'email-magic-link',
    ];
}

function fc_email_magic_link_flow_hash(string $emailSubject, string $scope): string
{
    return fc_secret_evidence_hash(
        'EMAIL_MAGIC_LINK_V1' . "\0" . $emailSubject . "\0" . $scope
    );
}

/** @return array<string,mixed>|null */
function fc_email_magic_link_transaction_by_id(PDO $pdo, int $transactionId, bool $forUpdate): ?array
{
    $sql =
        'SELECT id, public_id, intent, expected_provider, expected_user_id, state_hash, ' .
        '       browser_session_binding_hash, post_auth_destination_key, expires_at, consumed_at ' .
        'FROM auth_transactions WHERE id = :id LIMIT 1';
    if ($forUpdate) {
        if (!$pdo->inTransaction()) {
            throw new LogicException('Locking an email authentication transaction requires an active transaction.');
        }
        $sql .= ' FOR UPDATE';
    }
    $statement = $pdo->prepare($sql);
    $statement->execute([':id' => $transactionId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/** @return array<string,mixed>|null */
function fc_email_magic_link_active_challenge_by_flow(PDO $pdo, string $flowHash, bool $forUpdate): ?array
{
    $sql =
        'SELECT c.*, t.public_id AS transaction_public_id, t.intent AS transaction_intent, ' .
        '       t.expected_provider, t.expected_user_id, t.post_auth_destination_key, ' .
        '       t.expires_at AS transaction_expires_at, t.consumed_at AS transaction_consumed_at ' .
        'FROM email_magic_link_challenges c ' .
        'JOIN auth_transactions t ON t.id = c.auth_transaction_id ' .
        'WHERE c.flow_key_hash = :flow_hash AND c.challenge_status = \'ISSUED\' LIMIT 1';
    if ($forUpdate) {
        if (!$pdo->inTransaction()) {
            throw new LogicException('Locking an email magic-link flow requires an active transaction.');
        }
        $sql .= ' FOR UPDATE';
    }
    $statement = $pdo->prepare($sql);
    $statement->execute([':flow_hash' => $flowHash]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

function fc_email_magic_link_replace_challenge(PDO $pdo, int $challengeId): void
{
    $statement = $pdo->prepare(
        'UPDATE email_magic_link_challenges ' .
        'SET challenge_status = \'REPLACED\', replaced_at = CURRENT_TIMESTAMP(6), active_flow_key_hash = NULL ' .
        'WHERE id = :id AND challenge_status = \'ISSUED\''
    );
    $statement->execute([':id' => $challengeId]);
}

function fc_email_magic_link_release_bound_transaction(
    PDO $pdo,
    int $continuationId,
    int $transactionId
): void {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Changing invitation authentication provider requires an active transaction.');
    }

    $replaceChallenges = $pdo->prepare(
        'UPDATE email_magic_link_challenges ' .
        'SET challenge_status = \'REPLACED\', replaced_at = CURRENT_TIMESTAMP(6), active_flow_key_hash = NULL ' .
        'WHERE auth_transaction_id = :transaction_id AND challenge_status = \'ISSUED\''
    );
    $replaceChallenges->execute([':transaction_id' => $transactionId]);

    $invalidate = $pdo->prepare(
        'UPDATE auth_transactions ' .
        'SET consumed_at = CURRENT_TIMESTAMP(6), pkce_verifier_secret_envelope = NULL ' .
        'WHERE id = :id AND intent = \'LOGIN\' AND consumed_at IS NULL'
    );
    $invalidate->execute([':id' => $transactionId]);
    if ($invalidate->rowCount() !== 1) {
        throw new DomainException('email_magic_link_flow_unavailable');
    }

    $release = $pdo->prepare(
        'UPDATE auth_invitation_continuations ' .
        'SET auth_transaction_id = NULL, continuation_status = \'ISSUED\', login_bound_at = NULL ' .
        'WHERE id = :id AND continuation_status = \'LOGIN_BOUND\' AND auth_transaction_id = :transaction_id'
    );
    $release->execute([':id' => $continuationId, ':transaction_id' => $transactionId]);
    if ($release->rowCount() !== 1) {
        throw new DomainException('email_magic_link_flow_unavailable');
    }
}

/**
 * Issues hash-only challenge state. The raw token is returned to the immediate
 * mail-send caller and must never be logged, audited, or persisted.
 *
 * @return array{id:int,token:string,email_subject:string,expires_at:string}
 */
function fc_email_magic_link_issue(PDO $pdo, string $email, string $rawBrowserBinding): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Email magic-link issuance requires no active caller transaction.');
    }
    $emailSubject = fc_email_magic_link_subject($email);
    if ($rawBrowserBinding === '') {
        throw new InvalidArgumentException('Email magic-link request-browser evidence is required.');
    }

    $rawToken = fc_email_magic_link_token();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $maximumExpiry = $now->modify('+' . FC_EMAIL_MAGIC_LINK_TTL_SECONDS . ' seconds');

    $pdo->beginTransaction();
    try {
        $continuation = fc_auth_crew_invitation_continuation_login_context($pdo, true);
        $scope = $continuation === null ? 'APP_HOME' : (string) $continuation['public_id'];
        $destinationKey = $continuation === null
            ? 'APP_HOME'
            : FC_AUTH_CREW_INVITATION_DESTINATION;
        $flowHash = fc_email_magic_link_flow_hash($emailSubject, $scope);
        $authTransaction = null;
        $activeChallenge = null;

        if ($continuation !== null && (string) $continuation['continuation_status'] === 'LOGIN_BOUND') {
            $transactionId = (int) ($continuation['auth_transaction_id'] ?? 0);
            $authTransaction = fc_email_magic_link_transaction_by_id($pdo, $transactionId, true);
            if (
                $authTransaction === null
                || (string) $authTransaction['intent'] !== 'LOGIN'
                || $authTransaction['expected_user_id'] !== null
                || $authTransaction['consumed_at'] !== null
                || !hash_equals(
                    (string) $authTransaction['browser_session_binding_hash'],
                    fc_secret_evidence_hash($rawBrowserBinding)
                )
                || (string) $authTransaction['post_auth_destination_key'] !== FC_AUTH_CREW_INVITATION_DESTINATION
            ) {
                throw new DomainException('email_magic_link_flow_unavailable');
            }
            if ((string) $authTransaction['expected_provider'] === 'EMAIL') {
                $priorEmail = $pdo->prepare(
                    'SELECT email_subject FROM email_magic_link_challenges ' .
                    'WHERE auth_transaction_id = :transaction_id ORDER BY id DESC LIMIT 1 FOR UPDATE'
                );
                $priorEmail->execute([':transaction_id' => $transactionId]);
                $priorEmailSubject = $priorEmail->fetchColumn();
                if (!is_string($priorEmailSubject)) {
                    throw new DomainException('email_magic_link_flow_unavailable');
                }
                if (hash_equals($priorEmailSubject, $emailSubject)) {
                    $activeChallenge = fc_email_magic_link_active_challenge_by_flow($pdo, $flowHash, true);
                    if ($activeChallenge !== null && (int) $activeChallenge['auth_transaction_id'] !== $transactionId) {
                        throw new DomainException('email_magic_link_flow_unavailable');
                    }
                } else {
                    fc_email_magic_link_release_bound_transaction(
                        $pdo,
                        (int) $continuation['id'],
                        $transactionId
                    );
                    $continuation['continuation_status'] = 'ISSUED';
                    $continuation['auth_transaction_id'] = null;
                    $authTransaction = null;
                }
            } else {
                // The participant explicitly chose EMAIL after the login page had
                // prepared another provider. Invalidate that unused transaction
                // and atomically return this exact continuation to ISSUED before
                // binding its EMAIL transaction.
                fc_email_magic_link_release_bound_transaction(
                    $pdo,
                    (int) $continuation['id'],
                    $transactionId
                );
                $continuation['continuation_status'] = 'ISSUED';
                $continuation['auth_transaction_id'] = null;
                $authTransaction = null;
            }
        } elseif ($continuation === null) {
            $activeChallenge = fc_email_magic_link_active_challenge_by_flow($pdo, $flowHash, true);
            if ($activeChallenge !== null) {
                $candidate = fc_email_magic_link_transaction_by_id(
                    $pdo,
                    (int) $activeChallenge['auth_transaction_id'],
                    true
                );
                if (
                    $candidate !== null
                    && (string) $candidate['intent'] === 'LOGIN'
                    && (string) $candidate['expected_provider'] === 'EMAIL'
                    && $candidate['expected_user_id'] === null
                    && $candidate['consumed_at'] === null
                    && (string) $candidate['post_auth_destination_key'] === 'APP_HOME'
                ) {
                    $authTransaction = $candidate;
                }
            }
        }

        if ($activeChallenge !== null) {
            fc_email_magic_link_replace_challenge($pdo, (int) $activeChallenge['id']);
        }

        $expiresAt = $maximumExpiry;
        if ($continuation !== null) {
            $continuationExpiry = new DateTimeImmutable(
                (string) $continuation['expires_at'],
                new DateTimeZone('UTC')
            );
            if ($continuationExpiry < $expiresAt) {
                $expiresAt = $continuationExpiry;
            }
        }
        $ttlSeconds = $expiresAt->getTimestamp() - $now->getTimestamp();
        if ($ttlSeconds < 30) {
            throw new DomainException('email_magic_link_flow_unavailable');
        }

        if ($authTransaction === null) {
            $created = fc_auth_transaction_create(
                $pdo,
                'LOGIN',
                'EMAIL',
                null,
                $rawToken,
                $rawBrowserBinding,
                $destinationKey,
                null,
                null,
                min(FC_EMAIL_MAGIC_LINK_TTL_SECONDS, $ttlSeconds)
            );
            $authTransaction = fc_email_magic_link_transaction_by_id($pdo, (int) $created['id'], true);
            if ($authTransaction === null) {
                throw new RuntimeException('Email authentication transaction could not be loaded.');
            }
            if ($continuation !== null) {
                fc_auth_crew_invitation_continuation_bind_login_transaction(
                    $pdo,
                    (string) $continuation['public_id'],
                    (int) $authTransaction['id']
                );
            }
        } else {
            $replaceState = $pdo->prepare(
                'UPDATE auth_transactions ' .
                'SET state_hash = :state_hash, browser_session_binding_hash = :browser_hash, expires_at = :expires_at ' .
                'WHERE id = :id AND intent = \'LOGIN\' AND expected_provider = \'EMAIL\' ' .
                '  AND expected_user_id IS NULL AND consumed_at IS NULL'
            );
            $replaceState->execute([
                ':state_hash' => fc_secret_evidence_hash($rawToken),
                ':browser_hash' => fc_secret_evidence_hash($rawBrowserBinding),
                ':expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
                ':id' => (int) $authTransaction['id'],
            ]);
            if ($replaceState->rowCount() !== 1) {
                throw new DomainException('email_magic_link_flow_unavailable');
            }
        }

        $insert = $pdo->prepare(
            'INSERT INTO email_magic_link_challenges ( ' .
            ' public_id, auth_transaction_id, issuer, email_subject, token_hash, flow_key_hash, ' .
            ' active_flow_key_hash, expires_at ' .
            ') VALUES ( ' .
            ' :public_id, :transaction_id, :issuer, :email_subject, :token_hash, :flow_hash, ' .
            ' :active_flow_hash, :expires_at ' .
            ')'
        );
        $publicId = fc_new_public_id();
        $insert->execute([
            ':public_id' => $publicId,
            ':transaction_id' => (int) $authTransaction['id'],
            ':issuer' => FC_EMAIL_MAGIC_LINK_ISSUER,
            ':email_subject' => $emailSubject,
            ':token_hash' => fc_secret_evidence_hash($rawToken),
            ':flow_hash' => $flowHash,
            ':active_flow_hash' => $flowHash,
            ':expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
        ]);
        $challengeId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return [
        'id' => $challengeId,
        'token' => $rawToken,
        'email_subject' => $emailSubject,
        'expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
    ];
}

function fc_email_magic_link_invalidate_after_delivery_failure(PDO $pdo, int $challengeId): void
{
    $statement = $pdo->prepare(
        'UPDATE email_magic_link_challenges ' .
        'SET challenge_status = \'REPLACED\', replaced_at = CURRENT_TIMESTAMP(6), active_flow_key_hash = NULL ' .
        'WHERE id = :id AND challenge_status = \'ISSUED\''
    );
    $statement->execute([':id' => $challengeId]);
}

/**
 * Executes rate limits, issues one challenge, and sends through the existing
 * provider-neutral mail service. Callers must always return the generic request
 * response and must not expose this boolean.
 *
 * @param null|callable(array<string,mixed>):array{accepted:bool,driver:string,message_id:?string} $mailer
 */
function fc_email_magic_link_request(
    PDO $pdo,
    string $email,
    string $rawNetworkEvidence,
    ?callable $mailer = null
): bool {
    $networkSubject = $rawNetworkEvidence !== ''
        ? $rawNetworkEvidence
        : 'browser:' . fc_auth_browser_binding();
    $networkDecision = fc_rate_limit_consume(
        $pdo,
        'auth.email_magic.request.network',
        $networkSubject,
        20,
        900
    );

    try {
        $emailSubject = fc_email_magic_link_subject($email);
    } catch (InvalidArgumentException) {
        return false;
    }
    $emailDecision = fc_rate_limit_consume(
        $pdo,
        'auth.email_magic.request.email',
        $emailSubject,
        5,
        900
    );
    if (!$networkDecision['allowed'] || !$emailDecision['allowed']) {
        return false;
    }

    $challenge = fc_email_magic_link_issue($pdo, $emailSubject, fc_auth_browser_binding());
    $url = rtrim((string) fc_config()['url'], '/') .
        '/auth/email/confirm.php#token=' . rawurlencode((string) $challenge['token']);
    $message = fc_email_magic_link_message($emailSubject, $url);
    $mailer ??= static fn (array $outbound): array => fc_mail_send($outbound);

    try {
        $result = $mailer($message);
        if (!($result['accepted'] ?? false)) {
            fc_email_magic_link_invalidate_after_delivery_failure($pdo, (int) $challenge['id']);
            return false;
        }
        return true;
    } catch (Throwable $error) {
        fc_email_magic_link_invalidate_after_delivery_failure($pdo, (int) $challenge['id']);
        throw $error;
    } finally {
        unset($challenge['token'], $url, $message);
    }
}

/** @return array<string,mixed>|null */
function fc_email_magic_link_find_valid(
    PDO $pdo,
    string $rawToken,
    bool $forUpdate = false
): ?array {
    if (!fc_email_magic_link_token_valid_shape($rawToken)) {
        return null;
    }
    if ($forUpdate && !$pdo->inTransaction()) {
        throw new LogicException('Locking an email magic-link challenge requires an active transaction.');
    }

    $sql =
        'SELECT c.*, t.public_id AS transaction_public_id, t.intent AS transaction_intent, ' .
        '       t.expected_provider, t.expected_user_id, t.state_hash AS transaction_state_hash, ' .
        '       t.browser_session_binding_hash, t.post_auth_destination_key, ' .
        '       t.expires_at AS transaction_expires_at, t.consumed_at AS transaction_consumed_at ' .
        'FROM email_magic_link_challenges c ' .
        'JOIN auth_transactions t ON t.id = c.auth_transaction_id ' .
        'WHERE c.token_hash = :token_hash ' .
        '  AND c.challenge_status = \'ISSUED\' ' .
        '  AND c.expires_at > CURRENT_TIMESTAMP(6) ' .
        '  AND t.intent = \'LOGIN\' AND t.expected_provider = \'EMAIL\' ' .
        '  AND t.expected_user_id IS NULL AND t.consumed_at IS NULL ' .
        '  AND t.expires_at > CURRENT_TIMESTAMP(6) ' .
        '  AND t.state_hash = :state_hash ' .
        'LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $evidenceHash = fc_secret_evidence_hash($rawToken);
    $statement = $pdo->prepare($sql);
    $statement->execute([
        ':token_hash' => $evidenceHash,
        ':state_hash' => $evidenceHash,
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

function fc_email_magic_link_inspect(PDO $pdo, string $rawToken): bool
{
    return fc_email_magic_link_find_valid($pdo, $rawToken, false) !== null;
}

/**
 * Transfers the EMAIL LOGIN transaction from its request-browser evidence to
 * the browser that explicitly completes the bearer credential. This exception
 * is scoped to EMAIL; OAuth/OIDC provider transaction binding is unchanged.
 */
function fc_email_magic_link_rebind_transaction_to_arrival(
    PDO $pdo,
    int $transactionId,
    string $requestBrowserBindingHash,
    string $arrivalBrowserBinding
): void {
    if (!$pdo->inTransaction()) {
        throw new LogicException('EMAIL transaction arrival transfer requires an active database transaction.');
    }
    if ($arrivalBrowserBinding === '') {
        throw new InvalidArgumentException('EMAIL arrival-browser evidence is required.');
    }

    $arrivalHash = fc_secret_evidence_hash($arrivalBrowserBinding);
    if (hash_equals($requestBrowserBindingHash, $arrivalHash)) {
        return;
    }

    $statement = $pdo->prepare(
        'UPDATE auth_transactions ' .
        'SET browser_session_binding_hash = :arrival_hash ' .
        'WHERE id = :id AND intent = \'LOGIN\' AND expected_provider = \'EMAIL\' ' .
        '  AND expected_user_id IS NULL AND consumed_at IS NULL ' .
        '  AND browser_session_binding_hash = :request_hash'
    );
    $statement->execute([
        ':arrival_hash' => $arrivalHash,
        ':id' => $transactionId,
        ':request_hash' => $requestBrowserBindingHash,
    ]);
    if ($statement->rowCount() !== 1) {
        throw new DomainException('email_magic_link_invalid');
    }
}

/**
 * Applies opaque PHP-session continuation state only after SQL completion has
 * committed. A caller-owned transaction must call this after its own commit.
 *
 * @param array<string,mixed> $result
 */
function fc_email_magic_link_apply_committed_arrival_context(array $result): void
{
    $publicId = $result['continuation_public_id'] ?? null;
    if (is_string($publicId) && $publicId !== '') {
        fc_auth_crew_invitation_continuation_set_session($publicId);
    }
}

function fc_email_magic_link_ensure_verified_contact(PDO $pdo, int $userId, string $emailSubject): void
{
    fc_contact_email_ensure_verified($pdo, $userId, $emailSubject, 'EMAIL_MAGIC_LINK');
}

/** @return array{user:array<string,mixed>,identity:array<string,mixed>,new_account:bool,destination:string,continuation_public_id:?string} */
function fc_email_magic_link_complete(
    PDO $pdo,
    string $rawToken,
    string $rawBrowserBinding,
    string $rawSessionId,
    ?string $userAgentSummary = null,
    ?string $rawClientNetworkEvidence = null
): array {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $challenge = fc_email_magic_link_find_valid($pdo, $rawToken, true);
        if ($challenge === null) {
            throw new DomainException('email_magic_link_invalid');
        }

        $invitationContinuation = null;
        if ((string) $challenge['post_auth_destination_key'] === FC_AUTH_CREW_INVITATION_DESTINATION) {
            $invitationContinuation = fc_auth_crew_invitation_continuation_for_email_transaction(
                $pdo,
                (int) $challenge['auth_transaction_id'],
                true
            );
            if ($invitationContinuation === null) {
                throw new DomainException('invitation_continuation_invalid');
            }
        }

        $emailSubject = (string) $challenge['email_subject'];
        $identity = fc_auth_identity_find_oidc(
            $pdo,
            'EMAIL',
            FC_EMAIL_MAGIC_LINK_ISSUER,
            $emailSubject,
            true
        );
        $newAccount = false;
        $verifiedOwner = fc_contact_email_find_verified_owner($pdo, $emailSubject, true);

        if ($identity !== null || $verifiedOwner !== null) {
            if ($identity !== null && $verifiedOwner !== null
                && (int) $verifiedOwner['user_id'] !== (int) $identity['user_id']) {
                throw new DomainException('account_reconciliation_required');
            }
            if ($identity !== null && (string) $identity['identity_status'] !== 'ACTIVE') {
                throw new DomainException('fitcrew_account_access_denied');
            }
            // The completed mailbox proof may use the unique canonical VERIFIED
            // owner. A descriptive provider email alone never selects an account.
            $userId = (int) ($identity['user_id'] ?? $verifiedOwner['user_id']);
            $user = fc_user_find_by_id($pdo, $userId, true);
            if ($user === null || (string) $user['account_status'] !== 'ACTIVE') {
                throw new DomainException('fitcrew_account_access_denied');
            }
            if ($invitationContinuation !== null) {
                $snapshot = fc_auth_crew_invitation_product_snapshot(
                    $pdo,
                    (string) $invitationContinuation['invitation_public_id'],
                    (int) $invitationContinuation['invitation_generation'],
                    false
                );
                if ($snapshot === null) {
                    throw new DomainException('invitation_continuation_product_invalid');
                }
            }
        } else {
            if (fc_auth_identity_email_evidence_owners($pdo, $emailSubject) !== []) {
                throw new DomainException('account_reconciliation_required');
            }
            if ($invitationContinuation === null) {
                throw new DomainException('prelaunch_new_account_denied');
            }
            $snapshot = fc_auth_crew_invitation_product_snapshot(
                $pdo,
                (string) $invitationContinuation['invitation_public_id'],
                (int) $invitationContinuation['invitation_generation'],
                true
            );
            if ($snapshot === null) {
                throw new DomainException('prelaunch_invitation_denied');
            }
            fc_auth_crew_invitation_admission_claim(
                $pdo,
                (int) $invitationContinuation['id'],
                (string) $invitationContinuation['invitation_public_id']
            );
            $created = fc_user_create($pdo, null, 'ACTIVE', 'USER');
            $user = fc_user_find_by_id($pdo, (int) $created['id'], true);
            if ($user === null) {
                throw new RuntimeException('Unable to load newly created FitCrew user.');
            }
            $newAccount = true;
            fc_audit_event_write($pdo, [
                'actor_user_id' => (int) $user['id'],
                'event_type' => 'ACCOUNT_CREATED_EMAIL',
                'target_type' => 'USER',
                'target_id' => (string) $user['public_id'],
                'outcome' => 'SUCCESS',
                'metadata' => ['provider' => 'EMAIL'],
            ]);
        }

        if ($identity === null) {
            $createdIdentity = fc_auth_identity_create($pdo, (int) $user['id'], [
                'provider_key' => 'EMAIL',
                'issuer' => FC_EMAIL_MAGIC_LINK_ISSUER,
                'provider_subject' => $emailSubject,
                'email_at_provider' => $emailSubject,
                'provider_email_verified' => 1,
                'email_verification_observed_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
                'identity_status' => 'ACTIVE',
            ]);
            $identity = fc_auth_identity_find_oidc(
                $pdo, 'EMAIL', FC_EMAIL_MAGIC_LINK_ISSUER, $emailSubject, true
            );
            if ($identity === null || (int) $identity['id'] !== (int) $createdIdentity['id']) {
                throw new RuntimeException('Unable to load newly created EMAIL identity.');
            }
        }

        fc_auth_identity_update_provider_claims($pdo, (int) $identity['id'], [
            'email_at_provider' => $emailSubject,
            'provider_email_verified' => 1,
        ]);
        fc_email_magic_link_ensure_verified_contact($pdo, (int) $user['id'], $emailSubject);

        $sessionRecord = fc_session_record_create_with_policy(
            $pdo,
            (int) $user['id'],
            (int) $identity['id'],
            $rawSessionId,
            $userAgentSummary,
            $rawClientNetworkEvidence
        );
        if ($invitationContinuation !== null) {
            fc_auth_crew_invitation_continuation_transfer_email_arrival(
                $pdo,
                (int) $invitationContinuation['id'],
                $rawBrowserBinding,
                (int) $user['id'],
                (int) $sessionRecord['id'],
                $newAccount
            );
            if ($newAccount) {
                fc_auth_crew_invitation_admission_complete(
                    $pdo,
                    (int) $invitationContinuation['id'],
                    (int) $user['id']
                );
            }
        }

        fc_email_magic_link_rebind_transaction_to_arrival(
            $pdo,
            (int) $challenge['auth_transaction_id'],
            (string) $challenge['browser_session_binding_hash'],
            $rawBrowserBinding
        );
        $consumeChallenge = $pdo->prepare(
            'UPDATE email_magic_link_challenges ' .
            'SET challenge_status = \'CONSUMED\', consumed_at = CURRENT_TIMESTAMP(6), active_flow_key_hash = NULL ' .
            'WHERE id = :id AND challenge_status = \'ISSUED\' AND consumed_at IS NULL'
        );
        $consumeChallenge->execute([':id' => (int) $challenge['id']]);
        if ($consumeChallenge->rowCount() !== 1) {
            throw new DomainException('email_magic_link_invalid');
        }
        $transactionConsumed = fc_auth_transaction_consume(
            $pdo,
            (string) $challenge['transaction_public_id'],
            'LOGIN',
            'EMAIL',
            $rawToken,
            $rawBrowserBinding,
            null
        );
        if (!$transactionConsumed) {
            throw new DomainException('email_magic_link_invalid');
        }

        fc_audit_event_write($pdo, [
            'actor_user_id' => (int) $user['id'],
            'event_type' => 'EMAIL_AUTH_SUCCESS',
            'target_type' => 'USER',
            'target_id' => (string) $user['public_id'],
            'outcome' => 'SUCCESS',
            'metadata' => ['provider' => 'EMAIL', 'new_account' => $newAccount],
            'raw_client_evidence' => $rawClientNetworkEvidence,
        ]);
        fc_audit_event_write($pdo, [
            'actor_user_id' => (int) $user['id'],
            'event_type' => 'SESSION_CREATED',
            'target_type' => 'SESSION',
            'target_id' => (string) $sessionRecord['id'],
            'outcome' => 'SUCCESS',
            'metadata' => ['provider' => 'EMAIL'],
            'raw_client_evidence' => $rawClientNetworkEvidence,
        ]);

        $destination = fc_auth_destination_path((string) $challenge['post_auth_destination_key']);
        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'user' => $user,
            'identity' => $identity,
            'new_account' => $newAccount,
            'destination' => $destination,
            'continuation_public_id' => $invitationContinuation === null
                ? null
                : (string) $invitationContinuation['public_id'],
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function fc_email_magic_link_audit_rejection(PDO $pdo, string $reason): void
{
    $allowed = [
        'csrf_failed',
        'origin_failed',
        'rate_limited',
        'link_failed',
        'account_denied',
        'prelaunch_denied',
        'invitation_failed',
        'account_reconciliation_required',
        'unexpected_failure',
    ];
    if (!in_array($reason, $allowed, true)) {
        $reason = 'unexpected_failure';
    }
    fc_audit_event_write($pdo, [
        'event_type' => 'EMAIL_AUTH_REJECTED',
        'target_type' => 'AUTHENTICATION',
        'outcome' => 'DENIED',
        'metadata' => ['provider' => 'EMAIL', 'reason' => $reason],
    ]);
}
