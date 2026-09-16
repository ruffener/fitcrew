<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/product/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth/email_magic_link.php';

function emlf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function emlf_expect_domain(callable $operation, string $expected, string $label): void
{
    try {
        $operation();
    } catch (DomainException $error) {
        emlf_assert($error->getMessage() === $expected, $label . ' used: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException($label . ' was not rejected.');
}

/** @return array{public_id:string,generation:int,expires_at:string,email:string} */
function emlf_invitation(PDO $pdo, int $crewId, int $ownerId, string $email): array
{
    $publicId = fc_new_public_id();
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+2 hours')
        ->format('Y-m-d H:i:s.u');
    $insert = $pdo->prepare(
        'INSERT INTO crew_invitations ' .
        '(public_id,crew_id,invited_email,invited_by_user_id,token_hash,expires_at,transport_status) ' .
        'VALUES (:public_id,:crew_id,:email,:owner_id,:token_hash,:expires_at,\'TRANSPORT_ACCEPTED\')'
    );
    $insert->execute([
        ':public_id' => $publicId,
        ':crew_id' => $crewId,
        ':email' => $email,
        ':owner_id' => $ownerId,
        ':token_hash' => hash('sha256', random_bytes(32)),
        ':expires_at' => $expiresAt,
    ]);
    return ['public_id' => $publicId, 'generation' => 0, 'expires_at' => $expiresAt, 'email' => $email];
}

function emlf_begin_invitation(PDO $pdo, array $invitation): void
{
    $snapshot = fc_crew_invitation_auth_snapshot(
        $pdo,
        (string) $invitation['public_id'],
        (int) $invitation['generation']
    );
    emlf_assert($snapshot !== null, 'Website invitation snapshot is unavailable.');
    fc_auth_crew_invitation_continuation_issue(
        $pdo,
        (string) $snapshot['invitation_public_id'],
        (int) $snapshot['generation'],
        (string) $snapshot['expires_at']
    );
}

function emlf_use_browser(string $label, ?string $continuationPublicId = null): string
{
    $binding = hash('sha256', 'EMAIL_MAGIC_LINK_V1_TEST_BROWSER:' . $label);
    $_SESSION['fitcrew_auth_browser_binding'] = $binding;
    if ($continuationPublicId === null) {
        fc_auth_crew_invitation_continuation_clear_session();
    } else {
        fc_auth_crew_invitation_continuation_set_session($continuationPublicId);
    }

    return $binding;
}

/** @return array{id:int,token:string,email_subject:string,expires_at:string} */
function emlf_issue(PDO $pdo, string $email): array
{
    return fc_email_magic_link_issue($pdo, $email, fc_auth_browser_binding());
}

function emlf_transaction_id(PDO $pdo, int $challengeId): int
{
    $query = $pdo->prepare('SELECT auth_transaction_id FROM email_magic_link_challenges WHERE id=:id');
    $query->execute([':id' => $challengeId]);
    return (int) $query->fetchColumn();
}

/** @param list<string> $invitationIds @param list<int> $userIds */
function emlf_cleanup(PDO $pdo, array $invitationIds, int $crewId, array $userIds, array $extraTransactionIds): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->beginTransaction();
    try {
        $transactionIds = array_map('intval', $extraTransactionIds);
        $challengeTransactions = $pdo->query(
            "SELECT DISTINCT auth_transaction_id FROM email_magic_link_challenges " .
            "WHERE email_subject LIKE 'email-magic-%@example.test'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $transactionIds = array_merge($transactionIds, array_map('intval', $challengeTransactions));

        $pdo->exec("DELETE FROM email_magic_link_challenges WHERE email_subject LIKE 'email-magic-%@example.test'");

        if ($invitationIds !== []) {
            $marks = implode(',', array_fill(0, count($invitationIds), '?'));
            $continuationTransactions = $pdo->prepare(
                'SELECT auth_transaction_id FROM auth_invitation_continuations ' .
                'WHERE invitation_public_id IN (' . $marks . ') AND auth_transaction_id IS NOT NULL'
            );
            $continuationTransactions->execute($invitationIds);
            $transactionIds = array_merge($transactionIds, array_map('intval', $continuationTransactions->fetchAll(PDO::FETCH_COLUMN)));

            $deleteClaims = $pdo->prepare(
                'DELETE FROM auth_invitation_admission_claims WHERE invitation_public_id IN (' . $marks . ')'
            );
            $deleteClaims->execute($invitationIds);
            $deleteContinuations = $pdo->prepare(
                'DELETE FROM auth_invitation_continuations WHERE invitation_public_id IN (' . $marks . ')'
            );
            $deleteContinuations->execute($invitationIds);
            $deleteInvitations = $pdo->prepare('DELETE FROM crew_invitations WHERE public_id IN (' . $marks . ')');
            $deleteInvitations->execute($invitationIds);
        }

        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds !== []) {
            $marks = implode(',', array_fill(0, count($userIds), '?'));
            foreach (['audit_events' => 'actor_user_id', 'user_sessions' => 'user_id', 'user_contact_emails' => 'user_id', 'user_auth_identities' => 'user_id'] as $table => $column) {
                $delete = $pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (' . $marks . ')');
                $delete->execute($userIds);
            }
        }
        if ($crewId > 0) {
            $pdo->prepare('DELETE FROM crew_memberships WHERE crew_id=:crew_id')->execute([':crew_id' => $crewId]);
            $pdo->prepare('DELETE FROM crews WHERE id=:crew_id')->execute([':crew_id' => $crewId]);
        }
        if ($transactionIds !== []) {
            $transactionIds = array_values(array_unique(array_filter($transactionIds)));
            $marks = implode(',', array_fill(0, count($transactionIds), '?'));
            $delete = $pdo->prepare('DELETE FROM auth_transactions WHERE id IN (' . $marks . ')');
            $delete->execute($transactionIds);
        }
        if ($userIds !== []) {
            $marks = implode(',', array_fill(0, count($userIds), '?'));
            $delete = $pdo->prepare('DELETE FROM users WHERE id IN (' . $marks . ')');
            $delete->execute($userIds);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

$pdo = null;
$fixtureUserIds = [];
$fixtureInvitationIds = [];
$fixtureCrewId = 0;
$fixtureTransactionIds = [];
$failure = null;

try {
    $_ENV['SESSION_IDLE_SECONDS'] = '3600';
    $_ENV['SESSION_ABSOLUTE_SECONDS'] = '86400';
    $_ENV['PRELAUNCH_AUTH_PROOF_MODE'] = 'true';
    $_ENV['PRELAUNCH_AUTH_ALLOWED_EMAILS'] = 'ordinary-google-allowlist@example.test';
    $_ENV['GOOGLE_AUTH_CLIENT_ID'] = 'email-magic-link-proof.apps.googleusercontent.com';

    $pdo = fc_db();
    foreach (['email_magic_link_challenges', 'auth_invitation_continuations', 'auth_invitation_admission_claims'] as $table) {
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table_name'
        );
        $check->execute([':table_name' => $table]);
        emlf_assert((int) $check->fetchColumn() === 1, 'Apply migration 0330 first: missing ' . $table . '.');
    }
    $verifiedColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns " .
        "WHERE table_schema=DATABASE() AND table_name='user_contact_emails' " .
        "AND column_name='verified_email_canonical'"
    );
    emlf_assert((int) $verifiedColumn->fetchColumn() === 1, 'Apply migration 0340 before running this proof.');
    emlf_assert(session_id() !== '', 'CLI browser-binding session is unavailable.');

    $pdo->beginTransaction();
    $owner = fc_user_create($pdo, 'Email Magic Link Owner');
    $fixtureUserIds[] = (int) $owner['id'];
    $existing = fc_user_create($pdo, 'Existing Email User');
    $fixtureUserIds[] = (int) $existing['id'];
    $existingIdentity = fc_auth_identity_create($pdo, (int) $existing['id'], [
        'provider_key' => 'EMAIL',
        'issuer' => FC_EMAIL_MAGIC_LINK_ISSUER,
        'provider_subject' => 'email-magic-existing@example.test',
        'email_at_provider' => 'email-magic-existing@example.test',
        'provider_email_verified' => 1,
        'email_verification_observed_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ]);
    $googleOwner = fc_user_create($pdo, 'Matching Google User');
    $fixtureUserIds[] = (int) $googleOwner['id'];
    fc_auth_identity_create($pdo, (int) $googleOwner['id'], [
        'provider_key' => 'GOOGLE',
        'issuer' => 'https://accounts.google.com',
        'provider_subject' => 'email-magic-google-subject',
        'email_at_provider' => 'email-magic-match@example.test',
        'provider_email_verified' => 1,
        'email_verification_observed_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ]);
    fc_auth_identity_create($pdo, (int) $googleOwner['id'], [
        'provider_key' => 'MICROSOFT',
        'issuer' => 'https://login.microsoftonline.com/email-magic-test/v2.0',
        'provider_tenant_id' => 'email-magic-test-tenant',
        'provider_object_id' => 'email-magic-test-object',
        'email_at_provider' => 'email-magic-unknown-evidence@example.test',
        'provider_email_verified' => null,
    ]);
    $contactOwner = fc_user_create($pdo, 'Canonical Email Owner');
    $fixtureUserIds[] = (int) $contactOwner['id'];
    fc_contact_email_create(
        $pdo,
        (int) $contactOwner['id'],
        'Email-Magic-Contact-Owned@Example.test',
        'EMAIL_MAGIC_LINK',
        true,
        'VERIFIED',
        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')
    );
    $crewPublicId = fc_new_public_id();
    $crew = $pdo->prepare('INSERT INTO crews (public_id,display_name,owner_user_id) VALUES (:public_id,:name,:owner_id)');
    $crew->execute([':public_id' => $crewPublicId, ':name' => 'Email Magic Link Crew', ':owner_id' => (int) $owner['id']]);
    $fixtureCrewId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO crew_memberships (crew_id,user_id,role_code,membership_status) ' .
        'VALUES (:crew_id,:user_id,\'OWNER\',\'ACTIVE\')'
    )->execute([':crew_id' => $fixtureCrewId, ':user_id' => (int) $owner['id']]);
    $invitations = [
        'A' => emlf_invitation($pdo, $fixtureCrewId, (int) $owner['id'], 'invited-address-differs@example.test'),
        'B' => emlf_invitation($pdo, $fixtureCrewId, (int) $owner['id'], 'rollback-invite@example.test'),
        'C' => emlf_invitation($pdo, $fixtureCrewId, (int) $owner['id'], 'federated-match-invite@example.test'),
        'D' => emlf_invitation($pdo, $fixtureCrewId, (int) $owner['id'], 'provider-switch-invite@example.test'),
        'E' => emlf_invitation($pdo, $fixtureCrewId, (int) $owner['id'], 'unknown-evidence-invite@example.test'),
        'F' => emlf_invitation($pdo, $fixtureCrewId, (int) $owner['id'], 'owned-contact-invite@example.test'),
    ];
    foreach ($invitations as $invitation) {
        $fixtureInvitationIds[] = (string) $invitation['public_id'];
    }
    $pdo->commit();
    $baselineMemberships = (int) $pdo->query('SELECT COUNT(*) FROM crew_memberships')->fetchColumn();

    $invalidToken = fc_email_magic_link_token();
    emlf_expect_domain(
        fn () => fc_email_magic_link_complete(
            $pdo,
            $invalidToken,
            emlf_use_browser('invalid-arrival'),
            'email-magic-invalid-session'
        ),
        'email_magic_link_invalid',
        'Unknown bearer token'
    );

    // Existing EMAIL identity resolves cross-browser by issuer+mailbox and
    // receives a session in the arrival browser.
    $existingRequestBrowser = emlf_use_browser('existing-request-desktop');
    $first = emlf_issue($pdo, 'EMAIL-MAGIC-EXISTING@EXAMPLE.TEST');
    $stored = $pdo->prepare('SELECT token_hash,email_subject FROM email_magic_link_challenges WHERE id=:id');
    $stored->execute([':id' => (int) $first['id']]);
    $storedRow = $stored->fetch(PDO::FETCH_ASSOC);
    emlf_assert(is_array($storedRow), 'Issued challenge was not stored.');
    emlf_assert(!hash_equals((string) $first['token'], (string) $storedRow['token_hash']), 'Raw token was stored.');
    emlf_assert(
        hash_equals(fc_secret_evidence_hash((string) $first['token']), (string) $storedRow['token_hash']),
        'Stored token evidence is incorrect.'
    );
    emlf_assert($storedRow['email_subject'] === 'email-magic-existing@example.test', 'Mailbox subject was not canonicalized.');
    $existingArrivalBrowser = emlf_use_browser('existing-arrival-mobile');
    emlf_assert(!hash_equals($existingRequestBrowser, $existingArrivalBrowser), 'Cross-browser fixture reused one browser.');
    emlf_assert(fc_email_magic_link_inspect($pdo, (string) $first['token']), 'Valid token failed arrival-browser inspection.');
    $existingResult = fc_email_magic_link_complete(
        $pdo,
        (string) $first['token'],
        $existingArrivalBrowser,
        'email-magic-existing-session',
        'FitCrew EMAIL proof',
        '127.0.0.1'
    );
    emlf_assert($existingResult['new_account'] === false, 'Existing EMAIL identity created a new account.');
    emlf_assert((int) $existingResult['user']['id'] === (int) $existing['id'], 'Existing EMAIL identity resolved another user.');
    emlf_assert($existingResult['destination'] === '/app.php', 'Normal EMAIL login returned to the wrong destination.');
    emlf_expect_domain(
        fn () => fc_email_magic_link_complete($pdo, (string) $first['token'], $existingRequestBrowser, 'email-magic-reuse-session'),
        'email_magic_link_invalid',
        'Consumed token reuse'
    );
    $contact = $pdo->prepare(
        'SELECT verification_status FROM user_contact_emails WHERE user_id=:user_id AND email_canonical=:email'
    );
    $contact->execute([':user_id' => (int) $existing['id'], ':email' => 'email-magic-existing@example.test']);
    emlf_assert($contact->fetchColumn() === 'VERIFIED', 'EMAIL proof did not verify contact on the same user.');

    // Replacement invalidates the older outstanding link for the same flow.
    emlf_use_browser('replacement-request-a');
    $old = emlf_issue($pdo, 'email-magic-existing@example.test');
    emlf_use_browser('replacement-request-b');
    $replacement = emlf_issue($pdo, 'email-magic-existing@example.test');
    emlf_assert(!fc_email_magic_link_inspect($pdo, (string) $old['token']), 'Replaced token remained valid.');
    emlf_assert(fc_email_magic_link_inspect($pdo, (string) $replacement['token']), 'Replacement token is invalid.');
    $replacementArrival = emlf_use_browser('replacement-arrival');
    fc_email_magic_link_complete($pdo, (string) $replacement['token'], $replacementArrival, 'email-magic-replacement-session');

    // The cross-browser exception does not break the ordinary same-browser path.
    $sameBrowser = emlf_use_browser('same-browser-request-arrival');
    $sameBrowserChallenge = emlf_issue($pdo, 'email-magic-existing@example.test');
    $sameBrowserResult = fc_email_magic_link_complete(
        $pdo,
        (string) $sameBrowserChallenge['token'],
        $sameBrowser,
        'email-magic-same-browser-session'
    );
    emlf_assert(
        (int) $sameBrowserResult['user']['id'] === (int) $existing['id'],
        'Same-browser EMAIL login regressed.'
    );

    // Choosing EMAIL atomically replaces the exact unused Google transaction
    // which the invitation login page prepared for this same continuation.
    $switchRequestBrowser = emlf_use_browser('provider-switch-request');
    emlf_begin_invitation($pdo, $invitations['D']);
    $switchContinuationPublicId = fc_auth_crew_invitation_continuation_session_public_id();
    emlf_assert(is_string($switchContinuationPublicId), 'Provider-switch continuation pointer is unavailable.');
    $preparedGoogle = fc_google_prepare_login_transaction($pdo);
    $preparedGoogleId = $pdo->prepare('SELECT id FROM auth_transactions WHERE public_id=:public_id');
    $preparedGoogleId->execute([':public_id' => (string) $preparedGoogle['transaction_id']]);
    $fixtureTransactionIds[] = (int) $preparedGoogleId->fetchColumn();
    $emailSwitch = emlf_issue($pdo, 'email-magic-existing@example.test');
    emlf_assert(
        fc_auth_transaction_find_valid(
            $pdo,
            (string) $preparedGoogle['transaction_id'],
            'LOGIN',
            'GOOGLE',
            (string) $preparedGoogle['state'],
            $switchRequestBrowser,
            null
        ) === null,
        'EMAIL selection left the prior invitation-bound Google transaction usable.'
    );
    $switchArrivalBrowser = emlf_use_browser('provider-switch-arrival');
    $switched = fc_email_magic_link_complete(
        $pdo,
        (string) $emailSwitch['token'],
        $switchArrivalBrowser,
        'email-magic-provider-switch-session'
    );
    fc_email_magic_link_apply_committed_arrival_context($switched);
    emlf_assert(
        $switched['new_account'] === false && $switched['destination'] === '/crew-invite.php',
        'EMAIL provider switch did not preserve exact invitation continuation.'
    );
    emlf_assert(
        fc_auth_crew_invitation_continuation_session_public_id() === $switchContinuationPublicId,
        'Arrival browser did not receive the transferred continuation pointer.'
    );
    $arrivalContinuation = fc_auth_crew_invitation_continuation_find_for_browser(
        $pdo,
        $switchContinuationPublicId,
        ['AUTHENTICATED']
    );
    emlf_assert($arrivalContinuation !== null, 'Arrival browser cannot resolve the transferred continuation.');
    emlf_use_browser('provider-switch-request', $switchContinuationPublicId);
    emlf_assert(
        fc_auth_crew_invitation_continuation_find_for_browser(
            $pdo,
            $switchContinuationPublicId,
            ['AUTHENTICATED']
        ) === null,
        'Original browser retained the transferred continuation binding.'
    );
    fc_auth_crew_invitation_continuation_clear_session();

    // Desktop request to mobile-equivalent arrival succeeds; elapsed expiry
    // still fails closed independently of browser identity.
    emlf_use_browser('desktop-request');
    $crossBrowser = emlf_issue($pdo, 'email-magic-existing@example.test');
    $mobileArrival = emlf_use_browser('mobile-arrival');
    $crossBrowserResult = fc_email_magic_link_complete(
        $pdo,
        (string) $crossBrowser['token'],
        $mobileArrival,
        'email-magic-mobile-arrival-session'
    );
    emlf_assert(
        (int) $crossBrowserResult['user']['id'] === (int) $existing['id'],
        'Desktop-to-mobile EMAIL login did not resolve the existing user.'
    );
    emlf_use_browser('expiry-request');
    $expired = emlf_issue($pdo, 'email-magic-expired@example.test');
    $expireChallenge = $pdo->prepare(
        'UPDATE email_magic_link_challenges SET created_at=DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 15 MINUTE), ' .
        'expires_at=DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id=:id'
    );
    $expireChallenge->execute([':id' => (int) $expired['id']]);
    $expireTransaction = $pdo->prepare(
        'UPDATE auth_transactions SET created_at=DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 15 MINUTE), ' .
        'expires_at=DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id=:id'
    );
    $expireTransaction->execute([':id' => emlf_transaction_id($pdo, (int) $expired['id'])]);
    emlf_use_browser('expiry-arrival');
    emlf_assert(!fc_email_magic_link_inspect($pdo, (string) $expired['token']), 'Expired token remained valid.');

    // A matching Google provider-email claim cannot select/link its user and
    // is surfaced as a reconciliation requirement even outside invitation entry.
    $beforeUnknownUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    emlf_use_browser('unknown-request');
    $unknown = emlf_issue($pdo, 'email-magic-match@example.test');
    $unknownArrival = emlf_use_browser('unknown-arrival');
    emlf_expect_domain(
        fn () => fc_email_magic_link_complete($pdo, (string) $unknown['token'], $unknownArrival, 'email-magic-unknown-session'),
        'account_reconciliation_required',
        'Provider-email reconciliation outside invitation'
    );
    emlf_assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $beforeUnknownUsers, 'Denied EMAIL login created a user.');
    $emailIdentityCount = $pdo->prepare(
        'SELECT COUNT(*) FROM user_auth_identities WHERE provider_key=\'EMAIL\' AND provider_subject=:subject'
    );
    $emailIdentityCount->execute([':subject' => 'email-magic-match@example.test']);
    emlf_assert((int) $emailIdentityCount->fetchColumn() === 0, 'Google provider email auto-linked an EMAIL identity.');

    emlf_use_browser('unknown-no-evidence-request');
    $unknownNoEvidence = emlf_issue($pdo, 'email-magic-no-evidence@example.test');
    $unknownNoEvidenceArrival = emlf_use_browser('unknown-no-evidence-arrival');
    emlf_expect_domain(
        fn () => fc_email_magic_link_complete(
            $pdo,
            (string) $unknownNoEvidence['token'],
            $unknownNoEvidenceArrival,
            'email-magic-no-evidence-session'
        ),
        'prelaunch_new_account_denied',
        'Unknown EMAIL identity outside invitation'
    );

    // One valid invitation admits one EMAIL user without comparing invitation email.
    emlf_use_browser('admission-request');
    emlf_begin_invitation($pdo, $invitations['A']);
    $admissionContinuationPublicId = fc_auth_crew_invitation_continuation_session_public_id();
    emlf_assert(is_string($admissionContinuationPublicId), 'Admission continuation pointer is unavailable.');
    $admission = emlf_issue($pdo, 'email-magic-new@example.test');
    $admissionArrivalBrowser = emlf_use_browser('admission-arrival');
    $admitted = fc_email_magic_link_complete(
        $pdo,
        (string) $admission['token'],
        $admissionArrivalBrowser,
        'email-magic-admitted-session'
    );
    fc_email_magic_link_apply_committed_arrival_context($admitted);
    $fixtureUserIds[] = (int) $admitted['user']['id'];
    emlf_assert($admitted['new_account'] === true, 'Valid invitation did not admit a new EMAIL identity.');
    emlf_assert($admitted['destination'] === '/crew-invite.php', 'Invitation EMAIL login returned to the wrong path.');
    emlf_assert($invitations['A']['email'] !== 'email-magic-new@example.test', 'Fixture did not prove differing emails.');
    $contact->execute([':user_id' => (int) $admitted['user']['id'], ':email' => 'email-magic-new@example.test']);
    emlf_assert($contact->fetchColumn() === 'VERIFIED', 'Admitted EMAIL user lacks its verified same-user contact.');
    emlf_assert((int) $pdo->query('SELECT COUNT(*) FROM crew_memberships')->fetchColumn() === $baselineMemberships, 'Auth created Crew membership.');
    $continuationBinding = $pdo->prepare(
        'SELECT browser_session_binding_hash FROM auth_invitation_continuations WHERE public_id=:public_id'
    );
    $continuationBinding->execute([':public_id' => $admissionContinuationPublicId]);
    emlf_assert(
        hash_equals(fc_secret_evidence_hash($admissionArrivalBrowser), (string) $continuationBinding->fetchColumn()),
        'Invitation continuation did not transfer to the arrival browser.'
    );
    emlf_use_browser('admission-request', $admissionContinuationPublicId);
    emlf_assert(
        fc_auth_crew_invitation_continuation_find_for_browser(
            $pdo,
            $admissionContinuationPublicId,
            ['AUTHENTICATED']
        ) === null,
        'Original admission browser retained post-transfer authority.'
    );

    // The same logical invitation cannot admit a second account through Google.
    fc_auth_crew_invitation_continuation_clear_session();
    emlf_use_browser('second-provider-request');
    emlf_begin_invitation($pdo, $invitations['A']);
    $google = fc_google_prepare_login_transaction($pdo);
    $googleTransactionId = $pdo->prepare('SELECT id FROM auth_transactions WHERE public_id=:public_id');
    $googleTransactionId->execute([':public_id' => (string) $google['transaction_id']]);
    $fixtureTransactionIds[] = (int) $googleTransactionId->fetchColumn();
    $beforeSecondUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    emlf_expect_domain(
        fn () => fc_google_complete_verified_login(
            $pdo,
            (string) $google['transaction_id'],
            (string) $google['state'],
            fc_auth_browser_binding(),
            [
                'issuer' => 'https://accounts.google.com',
                'provider_subject' => 'email-magic-second-provider-subject',
                'email_at_provider' => 'another-provider-address@example.test',
                'provider_email_verified' => 1,
                'display_name' => 'Second Provider Attempt',
            ],
            'email-magic-second-provider-session'
        ),
        'prelaunch_invitation_admission_claimed',
        'Cross-provider second admission'
    );
    emlf_assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $beforeSecondUsers, 'Losing Google admission created a user.');
    fc_auth_crew_invitation_continuation_clear_session();

    // A caller rollback removes the admission claim/account/session and leaves a legitimate retry.
    $rollbackRequestBrowser = emlf_use_browser('rollback-request');
    emlf_begin_invitation($pdo, $invitations['B']);
    $rollbackChallenge = emlf_issue($pdo, 'email-magic-rollback@example.test');
    $rollbackArrivalBrowser = emlf_use_browser('rollback-arrival');
    $beforeRollbackUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $pdo->beginTransaction();
    $rolledBack = fc_email_magic_link_complete(
        $pdo,
        (string) $rollbackChallenge['token'],
        $rollbackArrivalBrowser,
        'email-magic-rollback-session-1'
    );
    emlf_assert($rolledBack['new_account'] === true, 'Rollback attempt did not reach provisional success.');
    $pdo->rollBack();
    emlf_assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $beforeRollbackUsers, 'Rolled-back admission left a user.');
    emlf_assert(fc_email_magic_link_inspect($pdo, (string) $rollbackChallenge['token']), 'Rollback burned the magic link.');
    $rollbackTransactionBinding = $pdo->prepare(
        'SELECT t.browser_session_binding_hash FROM auth_transactions t ' .
        'JOIN email_magic_link_challenges c ON c.auth_transaction_id=t.id WHERE c.id=:id'
    );
    $rollbackTransactionBinding->execute([':id' => (int) $rollbackChallenge['id']]);
    emlf_assert(
        hash_equals(fc_secret_evidence_hash($rollbackRequestBrowser), (string) $rollbackTransactionBinding->fetchColumn()),
        'Rollback retained the provisional arrival-browser transaction binding.'
    );
    $rollbackRetryBrowser = emlf_use_browser('rollback-retry-arrival');
    $retry = fc_email_magic_link_complete(
        $pdo,
        (string) $rollbackChallenge['token'],
        $rollbackRetryBrowser,
        'email-magic-rollback-session-2'
    );
    fc_email_magic_link_apply_committed_arrival_context($retry);
    $fixtureUserIds[] = (int) $retry['user']['id'];
    emlf_assert($retry['new_account'] === true, 'Legitimate retry after rollback failed.');

    // Provider-email equality is reconciliation evidence, never an automatic
    // link, merge, or authority to create a second canonical email owner.
    fc_auth_crew_invitation_continuation_clear_session();
    emlf_use_browser('federated-request');
    emlf_begin_invitation($pdo, $invitations['C']);
    $federatedMatch = emlf_issue($pdo, 'email-magic-match@example.test');
    $federatedArrival = emlf_use_browser('federated-arrival');
    $usersBeforeReconciliation = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    emlf_expect_domain(
        fn () => fc_email_magic_link_complete(
            $pdo,
            (string) $federatedMatch['token'],
            $federatedArrival,
            'email-magic-federated-match-session'
        ),
        'account_reconciliation_required',
        'Provider-email reconciliation conflict'
    );
    emlf_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $usersBeforeReconciliation,
        'Provider-email reconciliation conflict created or merged a user.'
    );

    fc_auth_crew_invitation_continuation_clear_session();
    emlf_use_browser('unknown-evidence-request');
    emlf_begin_invitation($pdo, $invitations['E']);
    $unknownEvidence = emlf_issue($pdo, 'email-magic-unknown-evidence@example.test');
    $unknownEvidenceArrival = emlf_use_browser('unknown-evidence-arrival');
    emlf_expect_domain(
        fn () => fc_email_magic_link_complete(
            $pdo,
            (string) $unknownEvidence['token'],
            $unknownEvidenceArrival,
            'email-magic-unknown-evidence-session'
        ),
        'account_reconciliation_required',
        'Verification-UNKNOWN provider evidence conflict'
    );

    fc_auth_crew_invitation_continuation_clear_session();
    emlf_use_browser('owned-contact-request');
    emlf_begin_invitation($pdo, $invitations['F']);
    $ownedContact = emlf_issue($pdo, 'email-magic-contact-owned@example.TEST');
    $ownedContactArrival = emlf_use_browser('owned-contact-arrival');
    $usersBeforeOwnedContact = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    emlf_expect_domain(
        fn () => fc_email_magic_link_complete(
            $pdo,
            (string) $ownedContact['token'],
            $ownedContactArrival,
            'email-magic-owned-contact-session'
        ),
        'account_reconciliation_required',
        'Canonical verified-email owner without EMAIL identity'
    );
    emlf_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $usersBeforeOwnedContact,
        'Existing canonical verified-email claim allowed a second user.'
    );

    // Exact server-side rate limits execute without retaining raw subjects.
    $pdo->beginTransaction();
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $decision = fc_rate_limit_consume(
            $pdo,
            'auth.email_magic.request.email',
            'email-magic-rate@example.test',
            5,
            900
        );
        emlf_assert($decision['allowed'] === ($attempt <= 5), 'Per-email rate limit failed at attempt ' . $attempt . '.');
    }
    for ($attempt = 1; $attempt <= 21; $attempt++) {
        $decision = fc_rate_limit_consume(
            $pdo,
            'auth.email_magic.complete.invalid.network',
            'email-magic-network-evidence',
            20,
            900
        );
        emlf_assert($decision['allowed'] === ($attempt <= 20), 'Completion rate limit failed at attempt ' . $attempt . '.');
    }
    $rawBucket = $pdo->prepare(
        'SELECT COUNT(*) FROM security_rate_limit_buckets WHERE bucket_key_hash IN (:email,:network)'
    );
    $rawBucket->execute([':email' => 'email-magic-rate@example.test', ':network' => 'email-magic-network-evidence']);
    emlf_assert((int) $rawBucket->fetchColumn() === 0, 'Rate limiter persisted raw subject evidence.');
    $pdo->rollBack();

    // Successful audit state contains no raw link token.
    $auditScan = $pdo->prepare('SELECT COUNT(*) FROM audit_events WHERE metadata_json LIKE :needle');
    $auditScan->execute([':needle' => '%' . (string) $first['token'] . '%']);
    emlf_assert((int) $auditScan->fetchColumn() === 0, 'Raw token entered audit metadata.');
    emlf_assert((int) $pdo->query('SELECT COUNT(*) FROM crew_memberships')->fetchColumn() === $baselineMemberships, 'Auth mutated membership state.');

    fwrite(STDOUT, "EMAIL_MAGIC_LINK_V1 foundation proof: PASS\n");
    fwrite(STDOUT, "- existing EMAIL identity → same user / real session: PASS\n");
    fwrite(STDOUT, "- canonical issuer+mailbox identity / verified same-user contact: PASS\n");
    fwrite(STDOUT, "- hash-only token evidence / raw token absent from audit: PASS\n");
    fwrite(STDOUT, "- consumed / replaced / expired token rejection: PASS\n");
    fwrite(STDOUT, "- invalid token rejection / same-browser compatibility: PASS\n");
    fwrite(STDOUT, "- explicit EMAIL choice replaces exact unused provider transaction: PASS\n");
    fwrite(STDOUT, "- request browser A → arrival browser B succeeds: PASS\n");
    fwrite(STDOUT, "- desktop → mobile-equivalent returning login: PASS\n");
    fwrite(STDOUT, "- invitation continuation transferred / original browser rejected: PASS\n");
    fwrite(STDOUT, "- provider-email reconciliation outside invitation creates nothing: PASS\n");
    fwrite(STDOUT, "- unknown EMAIL identity outside invitation remains prelaunch-denied: PASS\n");
    fwrite(STDOUT, "- invitation-bound new EMAIL user / fixed return: PASS\n");
    fwrite(STDOUT, "- invited email is not compared with EMAIL subject: PASS\n");
    fwrite(STDOUT, "- one logical invitation across EMAIL/Google: PASS\n");
    fwrite(STDOUT, "- rollback preserves admission and token retry: PASS\n");
    fwrite(STDOUT, "- provider-email evidence triggers reconciliation without link/merge: PASS\n");
    fwrite(STDOUT, "- verification-UNKNOWN provider email cannot transfer ownership: PASS\n");
    fwrite(STDOUT, "- existing canonical verified-email owner prevents second user: PASS\n");
    fwrite(STDOUT, "- executable per-email and completion-network limits: PASS\n");
    fwrite(STDOUT, "- rate-limit buckets retain keyed evidence only: PASS\n");
    fwrite(STDOUT, "- Crew membership untouched by Auth: PASS\n");
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($pdo instanceof PDO) {
        try {
            emlf_cleanup($pdo, $fixtureInvitationIds, $fixtureCrewId, $fixtureUserIds, $fixtureTransactionIds);
        } catch (Throwable $cleanupError) {
            if ($failure === null) {
                $failure = $cleanupError;
            }
        }
    }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, '[FAIL] ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
