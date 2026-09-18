<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once fc_path('inc/auth/email_identity_link.php');

function eil_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function eil_denied(callable $call, string $reason): void
{
    try { $call(); }
    catch (DomainException $error) {
        eil_assert($error->getMessage() === $reason, 'Unexpected rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected denial: ' . $reason);
}
function eil_count(PDO $pdo, string $table): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
function eil_query(PDO $pdo, string $sql, array $values): void
{
    $pdo->prepare($sql)->execute($values);
}

// Build LOGIN proof within the enclosing rollback-only fixture transaction.
// Ordinary issuance itself is exercised by email_magic_link_foundation_test.
function eil_login_fixture(PDO $pdo, string $email, string $browser): array
{
    $token = fc_email_magic_link_token();
    $transaction = fc_auth_transaction_create($pdo, 'LOGIN', 'EMAIL', null, $token, $browser, 'APP_HOME', null, null, 900);
    $flow = fc_email_magic_link_flow_hash($email, 'fixture:' . bin2hex(random_bytes(8)));
    eil_query($pdo, 'INSERT INTO email_magic_link_challenges (public_id,auth_transaction_id,issuer,email_subject,token_hash,flow_key_hash,active_flow_key_hash,expires_at) VALUES (?,?,?,?,?,?,?,?)', [fc_new_public_id(),$transaction['id'],FC_EMAIL_MAGIC_LINK_ISSUER,$email,fc_secret_evidence_hash($token),$flow,$flow,$transaction['expires_at']]);
    return ['id'=>(int)$pdo->lastInsertId(),'token'=>$token];
}

$pdo = null;
try {
    $pdo = fc_db();
    $pdo->beginTransaction();
    $suffix = bin2hex(random_bytes(8));
    $email = 'link-' . $suffix . '@example.test';
    $browser = str_repeat('a', 64);
    $_SESSION['fitcrew_auth_browser_binding'] = $browser;
    unset($_SESSION['fitcrew_crew_invitation_continuation']);
    fc_auth_crew_invitation_continuation_clear_session();
    $user = fc_user_create($pdo, 'Explicit link proof');
    $identity = fc_auth_identity_create($pdo, $user['id'], [
        'provider_key' => 'MICROSOFT', 'issuer' => 'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/v2.0',
        'provider_tenant_id' => '11111111-1111-4111-8111-111111111111',
        'provider_object_id' => '22222222-2222-4222-8222-' . substr($suffix, 0, 12),
        'email_at_provider' => $email, 'provider_email_verified' => null,
    ]);
    $rawSession = 'link-proof-session-' . $suffix;
    $session = fc_session_record_create_with_policy($pdo, $user['id'], $identity['id'], $rawSession);
    $other = fc_user_create($pdo, 'Other link proof');
    $otherIdentity = fc_auth_identity_create($pdo, $other['id'], [
        'provider_key' => 'GOOGLE', 'issuer' => 'https://accounts.google.com',
        'provider_subject' => 'link-other-' . $suffix,
    ]);
    $otherRaw = 'other-link-session-' . $suffix;
    fc_session_record_create_with_policy($pdo, $other['id'], $otherIdentity['id'], $otherRaw);
    $baselineUsers = eil_count($pdo, 'users');
    $baselineMemberships = eil_count($pdo, 'crew_memberships');
    $baselineSessions = eil_count($pdo, 'user_sessions');
    $baselineIdentities = eil_count($pdo, 'user_auth_identities');
    eil_assert(fc_auth_account_presence_for_email($pdo, $email) === 'UNKNOWN', 'Provider email counted as canonical ownership.');

    // Reproduce the production blocker: provider-only account cannot become EMAIL by inference.
    $ordinary = eil_login_fixture($pdo, $email, $browser);
    eil_denied(fn () => fc_email_magic_link_complete($pdo, $ordinary['token'], $browser, 'ordinary-' . $suffix), 'account_reconciliation_required');
    eil_assert(eil_count($pdo, 'user_auth_identities') === $baselineIdentities, 'Ordinary login auto-linked.');
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $ordinary['token'], $rawSession, $browser), 'email_identity_link_invalid');

    // Exact exported topology: mailbox matches Microsoft, Google is a different user.
    $wrongAccountLink = fc_email_identity_link_issue($pdo, $email, $otherRaw, $browser);
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $wrongAccountLink['token'], $otherRaw, $browser), 'account_reconciliation_required');
    eil_assert(eil_count($pdo, 'user_auth_identities') === $baselineIdentities, 'Google account absorbed Microsoft-email evidence.');

    $link = fc_email_identity_link_issue($pdo, '  ' . strtoupper($email) . ' ', $rawSession, $browser);
    eil_assert(!fc_email_magic_link_inspect($pdo, $link['token']), 'LINK_IDENTITY accepted as LOGIN.');
    eil_denied(fn () => fc_email_magic_link_complete($pdo, $link['token'], $browser, 'purpose-' . $suffix), 'email_magic_link_invalid');
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $link['token'], '', $browser), 'email_link_recent_signin_required');
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $link['token'], $otherRaw, $browser), 'email_identity_link_invalid');
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $link['token'], $rawSession, str_repeat('b', 64)), 'email_identity_link_invalid');
    $secondRaw = 'second-same-user-session-' . $suffix;
    fc_session_record_create_with_policy($pdo, $user['id'], $identity['id'], $secondRaw);
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $link['token'], $secondRaw, $browser), 'email_identity_link_invalid');
    eil_assert(eil_count($pdo, 'user_auth_identities') === $baselineIdentities, 'Rejected setup changed identities.');

    // Revoked session, stale authentication, account and provider status revalidated at completion.
    foreach ([
        ["UPDATE user_sessions SET revoked_at=CURRENT_TIMESTAMP(6),revocation_reason='test' WHERE id=?", [$session['id']], "UPDATE user_sessions SET revoked_at=NULL,revocation_reason=NULL WHERE id=?", [$session['id']]],
        ["UPDATE user_sessions SET created_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 11 MINUTE) WHERE id=?", [$session['id']], "UPDATE user_sessions SET created_at=CURRENT_TIMESTAMP(6) WHERE id=?", [$session['id']]],
        ["UPDATE users SET account_status='SUSPENDED' WHERE id=?", [$user['id']], "UPDATE users SET account_status='ACTIVE' WHERE id=?", [$user['id']]],
        ["UPDATE user_auth_identities SET identity_status='REVOKED' WHERE id=?", [$identity['id']], "UPDATE user_auth_identities SET identity_status='ACTIVE' WHERE id=?", [$identity['id']]],
    ] as [$disable, $params, $restore, $restoreParams]) {
        eil_query($pdo, $disable, $params);
        eil_denied(fn () => fc_email_identity_link_complete($pdo, $link['token'], $rawSession, $browser), 'email_link_recent_signin_required');
        eil_query($pdo, $restore, $restoreParams);
    }

    // Same authenticated user + that session + explicit mailbox proof resolves the blocker.
    $sessionsBeforeLink = eil_count($pdo, 'user_sessions');
    fc_email_identity_link_complete($pdo, $link['token'], $rawSession, $browser);
    $emailIdentity = fc_auth_identity_find_oidc($pdo, 'EMAIL', FC_EMAIL_MAGIC_LINK_ISSUER, $email);
    eil_assert($emailIdentity !== null && (int) $emailIdentity['user_id'] === $user['id'], 'Link did not retain the current account.');
    eil_assert(fc_auth_account_presence_for_email($pdo, $email) === 'KNOWN_ACCOUNT', 'Verified ownership not established.');
    eil_assert(eil_count($pdo, 'users') === $baselineUsers && eil_count($pdo, 'user_sessions') === $sessionsBeforeLink, 'Link created account/session.');
    eil_assert(eil_count($pdo, 'user_auth_identities') === $baselineIdentities + 1, 'Unexpected identity change.');
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $link['token'], $rawSession, $browser), 'email_identity_link_invalid');
    $login = eil_login_fixture($pdo, $email, $browser);
    $result = fc_email_magic_link_complete($pdo, $login['token'], str_repeat('c', 64), 'new-email-arrival-' . $suffix);
    eil_assert($result['new_account'] === false && (int) $result['user']['id'] === $user['id'], 'Later cross-browser EMAIL login did not use original account.');
    eil_assert(eil_count($pdo, 'users') === $baselineUsers, 'Later login created a duplicate user.');

    // Replacement and expiry never allow adding an extra method.
    $replaceEmail = 'replace-' . $suffix . '@example.test';
    $old = fc_email_identity_link_issue($pdo, $replaceEmail, $rawSession, $browser);
    $new = fc_email_identity_link_issue($pdo, $replaceEmail, $rawSession, $browser);
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $old['token'], $rawSession, $browser), 'email_identity_link_invalid');
    eil_query($pdo, 'UPDATE email_magic_link_challenges SET created_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 11 MINUTE),expires_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE id=?', [$new['id']]);
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $new['token'], $rawSession, $browser), 'email_identity_link_invalid');
    eil_assert(fc_auth_identity_find_oidc($pdo, 'EMAIL', FC_EMAIL_MAGIC_LINK_ISSUER, $replaceEmail) === null, 'Invalid link created an identity.');

    // Ownership appearing AFTER issuance is authoritative at completion.
    $conflictEmail = 'conflict-' . $suffix . '@example.test';
    $conflict = fc_email_identity_link_issue($pdo, $conflictEmail, $rawSession, $browser);
    fc_email_magic_link_ensure_verified_contact($pdo, $other['id'], $conflictEmail);
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $conflict['token'], $rawSession, $browser), 'account_reconciliation_required');
    eil_assert(fc_auth_identity_find_oidc($pdo, 'EMAIL', FC_EMAIL_MAGIC_LINK_ISSUER, $conflictEmail) === null, 'Foreign canonical owner was linked.');
    $foreignEmail = 'foreign-identity-' . $suffix . '@example.test';
    $foreign = fc_email_identity_link_issue($pdo, $foreignEmail, $rawSession, $browser);
    fc_auth_identity_create($pdo, $other['id'], ['provider_key'=>'EMAIL','issuer'=>FC_EMAIL_MAGIC_LINK_ISSUER,'provider_subject'=>$foreignEmail]);
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $foreign['token'], $rawSession, $browser), 'account_reconciliation_required');
    eil_assert((int) fc_auth_identity_find_oidc($pdo, 'EMAIL', FC_EMAIL_MAGIC_LINK_ISSUER, $foreignEmail)['user_id'] === $other['id'], 'Identity ownership transferred.');
    $evidenceEmail = 'foreign-evidence-' . $suffix . '@example.test';
    $evidence = fc_email_identity_link_issue($pdo, $evidenceEmail, $rawSession, $browser);
    eil_query($pdo, 'UPDATE user_auth_identities SET email_at_provider=? WHERE id=?', [$evidenceEmail,$otherIdentity['id']]);
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $evidence['token'], $rawSession, $browser), 'account_reconciliation_required');
    eil_assert(fc_auth_identity_find_oidc($pdo, 'EMAIL', FC_EMAIL_MAGIC_LINK_ISSUER, $evidenceEmail) === null, 'Conflicting provider evidence was ignored.');
    $inactiveEmail = 'inactive-' . $suffix . '@example.test';
    fc_auth_identity_create($pdo, $user['id'], ['provider_key'=>'EMAIL','issuer'=>FC_EMAIL_MAGIC_LINK_ISSUER,'provider_subject'=>$inactiveEmail,'identity_status'=>'REVOKED']);
    $inactive = fc_email_identity_link_issue($pdo, $inactiveEmail, $rawSession, $browser);
    eil_denied(fn () => fc_email_identity_link_complete($pdo, $inactive['token'], $rawSession, $browser), 'email_identity_link_unavailable');

    // Transaction rollback leaves proof unconsumed and allows a legitimate retry.
    $rollbackEmail = 'rollback-' . $suffix . '@example.test';
    $rollback = fc_email_identity_link_issue($pdo, $rollbackEmail, $rawSession, $browser);
    $pdo->exec('SAVEPOINT email_link_rollback');
    fc_email_identity_link_complete($pdo, $rollback['token'], $rawSession, $browser);
    $pdo->exec('ROLLBACK TO SAVEPOINT email_link_rollback');
    eil_assert(fc_auth_identity_find_oidc($pdo, 'EMAIL', FC_EMAIL_MAGIC_LINK_ISSUER, $rollbackEmail) === null, 'Rollback left an identity.');
    fc_email_identity_link_complete($pdo, $rollback['token'], $rawSession, $browser);

    // Fake mail transport only: verify request lifecycle and shared throttling without sending mail.
    $deliveryEmail = 'delivery-' . $suffix . '@example.test';
    $captured = [];
    $mailer = static function (array $mail) use (&$captured): array { $captured[] = $mail; return ['accepted'=>false]; };
    eil_assert(!fc_email_identity_link_request($pdo,$deliveryEmail,$rawSession,$browser,'link-proof-' . $suffix,$mailer), 'Delivery failure reported success.');
    eil_assert(count($captured) === 1 && str_contains($captured[0]['text_body'], '/auth/email/link-confirm.php#token='), 'Link mail lacks isolated fragment URL.');
    preg_match('/#token=([A-Za-z0-9_-]{43})/', $captured[0]['text_body'], $tokenMatch);
    eil_denied(fn () => fc_email_identity_link_complete($pdo,$tokenMatch[1],$rawSession,$browser), 'email_identity_link_invalid');
    $accepted = static function (array $mail) use (&$captured): array { $captured[] = $mail; return ['accepted'=>true]; };
    for ($i=0;$i<4;$i++) eil_assert(fc_email_identity_link_request($pdo,$deliveryEmail,$rawSession,$browser,'link-proof-' . $suffix,$accepted), 'Allowed request failed.');
    eil_assert(!fc_email_identity_link_request($pdo,$deliveryEmail,$rawSession,$browser,'link-proof-' . $suffix,$accepted) && count($captured)===5, 'Shared mailbox rate limit failed.');
    eil_assert(eil_count($pdo,'crew_memberships')===$baselineMemberships, 'Auth altered membership.');
    $audit = $pdo->prepare('SELECT COUNT(*) FROM audit_events WHERE metadata_json LIKE ?');
    $audit->execute(['%' . $link['token'] . '%']);
    eil_assert((int)$audit->fetchColumn()===0, 'Raw token leaked to audit.');
    $pdo->rollBack();
    fwrite(STDOUT, "EMAIL_IDENTITY_LINK foundation: PASS\n- Microsoft mailbox / separate Google user reproduced; explicit proof links same Microsoft user\n- subsequent EMAIL login works cross-browser without duplicate account\n- wrong purpose/user/browser/session, revoked/stale auth and inactive account rejected\n- replay/replacement/expiry, ownership conflicts and inactive method rejected\n- rollback, hash-only audit, delivery failure, shared limits and unchanged memberships proved\n");
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
