<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/product/bootstrap.php';

function acif_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function acif_claims(string $subject, string $email): array
{
    return [
        'issuer' => 'https://accounts.google.com',
        'provider_subject' => $subject,
        'email_at_provider' => $email,
        'provider_email_verified' => 1,
        'display_name' => 'Invitation Proof User',
    ];
}

function acif_expect_domain(callable $operation, string $expectedCode, string $label): void
{
    try {
        $operation();
    } catch (DomainException $error) {
        acif_assert(
            $error->getMessage() === $expectedCode,
            $label . ' used unexpected rejection: ' . $error->getMessage()
        );
        return;
    }
    throw new RuntimeException($label . ' was not denied.');
}

function acif_with_rollback(PDO $pdo, callable $operation): mixed
{
    acif_assert(!$pdo->inTransaction(), 'Test rollback helper received an active transaction.');
    $pdo->beginTransaction();
    try {
        $result = $operation();
        $pdo->rollBack();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** @return array{public_id:string,generation:int,expires_at:string,email:string} */
function acif_create_invitation(PDO $pdo, int $crewId, int $ownerUserId, string $email): array
{
    $publicId = fc_new_public_id();
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+2 hours')
        ->format('Y-m-d H:i:s.u');
    $statement = $pdo->prepare(
        'INSERT INTO crew_invitations ' .
        '    (public_id, crew_id, invited_email, invited_by_user_id, token_hash, expires_at) ' .
        'VALUES (:public_id, :crew_id, :email, :owner_id, :token_hash, :expires_at)'
    );
    $statement->execute([
        ':public_id' => $publicId,
        ':crew_id' => $crewId,
        ':email' => $email,
        ':owner_id' => $ownerUserId,
        ':token_hash' => hash('sha256', random_bytes(32)),
        ':expires_at' => $expiresAt,
    ]);

    return [
        'public_id' => $publicId,
        'generation' => 0,
        'expires_at' => $expiresAt,
        'email' => $email,
    ];
}

/** @return array{issued:array<string,mixed>,prepared:array<string,mixed>,browser_binding:string} */
function acif_prepare(PDO $pdo, array $invitation, ?int $generation = null): array
{
    $generation ??= (int) $invitation['generation'];
    $snapshot = fc_crew_invitation_auth_snapshot(
        $pdo,
        (string) $invitation['public_id'],
        $generation
    );
    acif_assert($snapshot !== null, 'Website invitation snapshot was unexpectedly unavailable.');
    $issued = fc_auth_crew_invitation_continuation_issue(
        $pdo,
        (string) $snapshot['invitation_public_id'],
        (int) $snapshot['generation'],
        (string) $snapshot['expires_at']
    );
    $browserBinding = fc_auth_browser_binding();
    $prepared = fc_google_prepare_login_transaction($pdo);

    return [
        'issued' => $issued,
        'prepared' => $prepared,
        'browser_binding' => $browserBinding,
    ];
}

function acif_complete(PDO $pdo, array $flow, array $claims, string $rawSessionId): array
{
    return fc_google_complete_verified_login(
        $pdo,
        (string) $flow['prepared']['transaction_id'],
        (string) $flow['prepared']['state'],
        (string) $flow['browser_binding'],
        $claims,
        $rawSessionId,
        'FitCrew invitation proof',
        '127.0.0.1'
    );
}

/** @param list<string> $invitationPublicIds @param list<int> $userIds */
function acif_cleanup(PDO $pdo, array $invitationPublicIds, int $crewId, array $userIds): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->beginTransaction();
    try {
        if ($invitationPublicIds !== []) {
            $invitationPlaceholders = implode(',', array_fill(0, count($invitationPublicIds), '?'));
            $transactionQuery = $pdo->prepare(
                'SELECT auth_transaction_id FROM auth_invitation_continuations ' .
                'WHERE invitation_public_id IN (' . $invitationPlaceholders . ') ' .
                'AND auth_transaction_id IS NOT NULL'
            );
            $transactionQuery->execute($invitationPublicIds);
            $transactionIds = array_values(array_unique(array_map(
                'intval',
                array_column($transactionQuery->fetchAll(PDO::FETCH_ASSOC), 'auth_transaction_id')
            )));

            $admittedUserQuery = $pdo->prepare(
                'SELECT admitted_user_id FROM auth_invitation_admission_claims ' .
                'WHERE invitation_public_id IN (' . $invitationPlaceholders . ') ' .
                'AND admitted_user_id IS NOT NULL'
            );
            $admittedUserQuery->execute($invitationPublicIds);
            $userIds = array_merge(
                $userIds,
                array_map('intval', array_column($admittedUserQuery->fetchAll(PDO::FETCH_ASSOC), 'admitted_user_id'))
            );

            $deleteClaims = $pdo->prepare(
                'DELETE FROM auth_invitation_admission_claims ' .
                'WHERE invitation_public_id IN (' . $invitationPlaceholders . ')'
            );
            $deleteClaims->execute($invitationPublicIds);
            $deleteContinuations = $pdo->prepare(
                'DELETE FROM auth_invitation_continuations ' .
                'WHERE invitation_public_id IN (' . $invitationPlaceholders . ')'
            );
            $deleteContinuations->execute($invitationPublicIds);

            if ($transactionIds !== []) {
                $transactionPlaceholders = implode(',', array_fill(0, count($transactionIds), '?'));
                $deleteTransactions = $pdo->prepare(
                    'DELETE FROM auth_transactions WHERE id IN (' . $transactionPlaceholders . ')'
                );
                $deleteTransactions->execute($transactionIds);
            }

            $deleteInvitations = $pdo->prepare(
                'DELETE FROM crew_invitations WHERE public_id IN (' . $invitationPlaceholders . ')'
            );
            $deleteInvitations->execute($invitationPublicIds);
        }

        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        if ($userIds !== []) {
            $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
            foreach (['audit_events', 'user_sessions', 'user_auth_identities', 'user_contact_emails'] as $table) {
                $column = $table === 'audit_events' ? 'actor_user_id' : 'user_id';
                $delete = $pdo->prepare(
                    'DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (' . $userPlaceholders . ')'
                );
                $delete->execute($userIds);
            }
        }

        if ($crewId > 0) {
            $pdo->prepare('DELETE FROM crew_memberships WHERE crew_id = :crew_id')
                ->execute([':crew_id' => $crewId]);
            $pdo->prepare('DELETE FROM crews WHERE id = :crew_id')
                ->execute([':crew_id' => $crewId]);
        }

        if ($userIds !== []) {
            $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
            $deleteUsers = $pdo->prepare('DELETE FROM users WHERE id IN (' . $userPlaceholders . ')');
            $deleteUsers->execute($userIds);
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
$fixtureInvitationIds = [];
$fixtureUserIds = [];
$fixtureCrewId = 0;
$failure = null;

try {
    $_ENV['GOOGLE_AUTH_CLIENT_ID'] = 'fitcrew-invitation-proof.apps.googleusercontent.com';
    $_ENV['PRELAUNCH_AUTH_PROOF_MODE'] = 'true';
    $_ENV['PRELAUNCH_AUTH_ALLOWED_EMAILS'] = 'ordinary-allowlist@example.com';
    $_ENV['SESSION_IDLE_SECONDS'] = '3600';
    $_ENV['SESSION_ABSOLUTE_SECONDS'] = '86400';

    $pdo = fc_db();
    foreach ([
        'auth_invitation_continuations',
        'auth_invitation_admission_claims',
        'security_rate_limit_buckets',
    ] as $requiredTable) {
        $query = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables ' .
            'WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $query->execute([':table_name' => $requiredTable]);
        acif_assert(
            (int) $query->fetchColumn() === 1,
            'Apply migration 0310 before running this proof: missing ' . $requiredTable . '.'
        );
    }
    acif_assert(
        function_exists('fc_crew_invitation_auth_snapshot'),
        'The real Website invitation validator was not loaded.'
    );
    acif_assert(session_id() !== '', 'A PHP session is required for browser-binding proof.');

    // Committed product fixtures let Auth prove issuance is never coupled to a
    // caller-owned SQL transaction. They are removed in the cleanup block.
    $pdo->beginTransaction();
    $owner = fc_user_create($pdo, 'Auth Invitation Test Owner');
    $fixtureUserIds[] = (int) $owner['id'];
    $crewPublicId = fc_new_public_id();
    $insertCrew = $pdo->prepare(
        'INSERT INTO crews (public_id, display_name, owner_user_id) VALUES (:public_id, :name, :owner_id)'
    );
    $insertCrew->execute([
        ':public_id' => $crewPublicId,
        ':name' => 'Auth Invitation Test Crew',
        ':owner_id' => (int) $owner['id'],
    ]);
    $fixtureCrewId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO crew_memberships (crew_id, user_id, role_code, membership_status) ' .
        'VALUES (:crew_id, :user_id, \'OWNER\', \'ACTIVE\')'
    )->execute([':crew_id' => $fixtureCrewId, ':user_id' => (int) $owner['id']]);

    $invitations = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $label) {
        $invitations[$label] = acif_create_invitation(
            $pdo,
            $fixtureCrewId,
            (int) $owner['id'],
            'auth-invitation-' . strtolower($label) . '@example.test'
        );
        $fixtureInvitationIds[] = $invitations[$label]['public_id'];
    }
    $pdo->commit();

    $baselineMemberships = (int) $pdo->query('SELECT COUNT(*) FROM crew_memberships')->fetchColumn();

    // Issuance refuses caller transactions before touching either DB or PHP session state.
    $pointerBeforeUnsafeIssue = fc_auth_crew_invitation_continuation_session_public_id();
    $continuationCountBeforeUnsafeIssue = (int) $pdo->query(
        'SELECT COUNT(*) FROM auth_invitation_continuations'
    )->fetchColumn();
    $pdo->beginTransaction();
    try {
        fc_auth_crew_invitation_continuation_issue(
            $pdo,
            $invitations['A']['public_id'],
            0,
            $invitations['A']['expires_at']
        );
        throw new RuntimeException('Continuation issuance accepted a caller-owned transaction.');
    } catch (LogicException $expected) {
        acif_assert(
            str_contains($expected->getMessage(), 'no active caller transaction'),
            'Unsafe issuance used an unexpected rejection.'
        );
        $pdo->rollBack();
    }
    acif_assert(
        fc_auth_crew_invitation_continuation_session_public_id() === $pointerBeforeUnsafeIssue,
        'Rejected issuance changed the PHP continuation pointer.'
    );
    acif_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM auth_invitation_continuations')->fetchColumn()
            === $continuationCountBeforeUnsafeIssue,
        'Rejected issuance persisted a continuation row.'
    );

    // First successful invitation-bound admission commits one user, identity,
    // session, continuation result, and logical-invitation claim atomically.
    $winnerFlow = acif_prepare($pdo, $invitations['A']);
    $winnerClaims = acif_claims(
        'family-alpha-logical-invitation-winner',
        'provider-address-differs@example.test'
    );
    $winner = acif_complete($pdo, $winnerFlow, $winnerClaims, session_id());
    $winnerUserId = (int) $winner['user']['id'];
    $fixtureUserIds[] = $winnerUserId;
    acif_assert($winner['new_account'] === true, 'Invitation did not admit the first new Google account.');
    acif_assert($winner['destination'] === '/crew-invite.php', 'Invitation returned to the wrong destination.');

    $claimQuery = $pdo->prepare(
        'SELECT admitted_user_id, completed_at FROM auth_invitation_admission_claims ' .
        'WHERE invitation_public_id = :invitation_public_id'
    );
    $claimQuery->execute([':invitation_public_id' => $invitations['A']['public_id']]);
    $winnerClaim = $claimQuery->fetch(PDO::FETCH_ASSOC);
    acif_assert(
        is_array($winnerClaim)
            && (int) $winnerClaim['admitted_user_id'] === $winnerUserId
            && $winnerClaim['completed_at'] !== null,
        'Winning logical-invitation admission claim was not completed.'
    );
    $providerEmail = $pdo->prepare(
        'SELECT email_at_provider FROM user_auth_identities WHERE id = :identity_id'
    );
    $providerEmail->execute([':identity_id' => (int) $winner['identity']['id']]);
    acif_assert(
        $providerEmail->fetchColumn() === 'provider-address-differs@example.test'
            && $winnerClaims['email_at_provider'] !== $invitations['A']['email'],
        'Invitation admission depended on matching provider and invited email.'
    );

    // Website receives only its three approved fields; internal Auth IDs remain private.
    $current = fc_auth_crew_invitation_continuation_current($pdo);
    acif_assert($current !== null, 'Website could not read the authenticated continuation.');
    acif_assert(
        array_keys($current) === ['invitation_public_id', 'generation', 'expires_at'],
        'Website-facing continuation exposed Auth internal identifiers.'
    );

    // A consume rolled back by Website leaves the PHP pointer and authenticated DB state usable.
    $winnerPointer = fc_auth_crew_invitation_continuation_session_public_id();
    $pdo->beginTransaction();
    acif_assert(
        fc_auth_crew_invitation_continuation_consume($pdo, $invitations['A']['public_id'], 0),
        'Website could not consume the authenticated continuation.'
    );
    acif_assert(
        fc_auth_crew_invitation_continuation_session_public_id() === $winnerPointer,
        'Transactional consume cleared PHP session state before commit.'
    );
    $pdo->rollBack();
    acif_assert(
        fc_auth_crew_invitation_continuation_current($pdo) !== null,
        'Rolled-back consume lost the authenticated browser continuation.'
    );

    // Successful consume also leaves the pointer until a later request observes
    // committed terminal state and clears it lazily.
    $pdo->beginTransaction();
    acif_assert(
        fc_auth_crew_invitation_continuation_consume($pdo, $invitations['A']['public_id'], 0),
        'Committed continuation consume failed.'
    );
    $pdo->commit();
    acif_assert(
        fc_auth_crew_invitation_continuation_session_public_id() === $winnerPointer,
        'Committed consume eagerly cleared PHP session state.'
    );
    acif_assert(
        fc_auth_crew_invitation_continuation_current($pdo) === null
            && fc_auth_crew_invitation_continuation_session_public_id() === null,
        'Committed terminal continuation was not cleared lazily.'
    );

    // A second continuation for the same logical invitation cannot admit another account.
    $duplicateFlow = acif_prepare($pdo, $invitations['A']);
    $beforeDuplicateUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $beforeDuplicateIdentities = (int) $pdo->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn();
    $beforeDuplicateSessions = (int) $pdo->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn();
    acif_with_rollback($pdo, function () use ($pdo, $duplicateFlow): void {
        acif_expect_domain(
            fn () => acif_complete(
                $pdo,
                $duplicateFlow,
                acif_claims('family-alpha-logical-invitation-loser', 'loser@example.test'),
                'family-alpha-loser-session'
            ),
            'prelaunch_invitation_admission_claimed',
            'Second continuation for one logical invitation'
        );
    });
    acif_assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $beforeDuplicateUsers, 'Losing continuation created a user.');
    acif_assert((int) $pdo->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn() === $beforeDuplicateIdentities, 'Losing continuation created an identity.');
    acif_assert((int) $pdo->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn() === $beforeDuplicateSessions, 'Losing continuation created a session.');

    // Resending rotates freshness but does not create a second admission slot.
    $resendExpiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+2 hours')
        ->format('Y-m-d H:i:s.u');
    $resendUpdate = $pdo->prepare(
        'UPDATE crew_invitations ' .
        'SET token_hash = :token_hash, expires_at = :expires_at, resend_count = resend_count + 1 ' .
        'WHERE public_id = :public_id'
    );
    $resendUpdate->execute([
        ':token_hash' => hash('sha256', random_bytes(32)),
        ':expires_at' => $resendExpiresAt,
        ':public_id' => $invitations['A']['public_id'],
    ]);
    $invitations['A']['generation'] = 1;
    $invitations['A']['expires_at'] = $resendExpiresAt;
    $resendFlow = acif_prepare($pdo, $invitations['A'], 1);
    acif_with_rollback($pdo, function () use ($pdo, $resendFlow): void {
        acif_expect_domain(
            fn () => acif_complete(
                $pdo,
                $resendFlow,
                acif_claims('family-alpha-resend-loser', 'resend-loser@example.test'),
                'family-alpha-resend-loser-session'
            ),
            'prelaunch_invitation_admission_claimed',
            'New generation of an already-claimed invitation'
        );
    });

    // An existing linked FitCrew user may continue without consuming invitation B's admission authority.
    $existingFlow = acif_prepare($pdo, $invitations['B']);
    $existing = acif_with_rollback(
        $pdo,
        fn () => acif_complete($pdo, $existingFlow, $winnerClaims, 'family-alpha-existing-session')
    );
    acif_assert($existing['new_account'] === false, 'Existing Google identity created another FitCrew account.');
    $claimQuery->execute([':invitation_public_id' => $invitations['B']['public_id']]);
    acif_assert($claimQuery->fetch(PDO::FETCH_ASSOC) === false, 'Existing user consumed new-account admission authority.');

    // A user who is already signed in may bind the current FitCrew session
    // directly, without a LOGIN transaction or admission claim.
    $signedInSnapshot = fc_crew_invitation_auth_snapshot($pdo, $invitations['H']['public_id'], 0);
    acif_assert($signedInSnapshot !== null, 'Signed-in invitation snapshot was unavailable.');
    fc_auth_crew_invitation_continuation_issue(
        $pdo,
        $signedInSnapshot['invitation_public_id'],
        $signedInSnapshot['generation'],
        $signedInSnapshot['expires_at']
    );
    $signedIn = fc_auth_crew_invitation_continuation_bind_existing_session($pdo, fc_current_user());
    acif_assert($signedIn['destination'] === '/crew-invite.php', 'Signed-in continuation returned to the wrong destination.');
    $signedInContinuation = $pdo->prepare(
        'SELECT auth_transaction_id FROM auth_invitation_continuations ' .
        'WHERE invitation_public_id = :invitation_public_id ORDER BY id DESC LIMIT 1'
    );
    $signedInContinuation->execute([':invitation_public_id' => $invitations['H']['public_id']]);
    $signedInContinuationRow = $signedInContinuation->fetch(PDO::FETCH_ASSOC);
    acif_assert(
        is_array($signedInContinuationRow) && $signedInContinuationRow['auth_transaction_id'] === null,
        'Signed-in continuation was unnecessarily bound to a LOGIN transaction.'
    );
    $claimQuery->execute([':invitation_public_id' => $invitations['H']['public_id']]);
    acif_assert($claimQuery->fetch(PDO::FETCH_ASSOC) === false, 'Signed-in user consumed new-account admission authority.');

    // A different logical invitation has independent authority.
    $independentFlow = acif_prepare($pdo, $invitations['C']);
    $independent = acif_with_rollback(
        $pdo,
        fn () => acif_complete(
            $pdo,
            $independentFlow,
            acif_claims('family-alpha-independent-invitation', 'independent@example.test'),
            'family-alpha-independent-session'
        )
    );
    acif_assert($independent['new_account'] === true, 'Independent logical invitation lacked its own admission authority.');

    // Rolling back a winning transaction rolls back its claim and every account artifact.
    $rollbackFlow = acif_prepare($pdo, $invitations['D']);
    $rollbackClaims = acif_claims('family-alpha-rollback-winner', 'rollback@example.test');
    $firstRollbackAttempt = acif_with_rollback($pdo, function () use ($pdo, $rollbackFlow, $rollbackClaims, $invitations): array {
        $result = acif_complete($pdo, $rollbackFlow, $rollbackClaims, 'family-alpha-rollback-session-1');
        $claim = $pdo->prepare(
            'SELECT COUNT(*) FROM auth_invitation_admission_claims WHERE invitation_public_id = :public_id'
        );
        $claim->execute([':public_id' => $invitations['D']['public_id']]);
        acif_assert((int) $claim->fetchColumn() === 1, 'Winning transaction did not create its admission claim.');
        return $result;
    });
    acif_assert($firstRollbackAttempt['new_account'] === true, 'Initial rollback admission did not reach success.');
    $claimQuery->execute([':invitation_public_id' => $invitations['D']['public_id']]);
    acif_assert($claimQuery->fetch(PDO::FETCH_ASSOC) === false, 'Rolled-back admission claim persisted.');
    $retryAfterRollback = acif_with_rollback(
        $pdo,
        fn () => acif_complete($pdo, $rollbackFlow, $rollbackClaims, 'family-alpha-rollback-session-2')
    );
    acif_assert($retryAfterRollback['new_account'] === true, 'Legitimate retry after rollback was denied.');

    // Actual Website validator rejects cancellation, elapsed expiry, and stale generation.
    foreach (['E' => 'CANCELLED', 'F' => 'EXPIRED', 'G' => 'ROTATED'] as $label => $invalidState) {
        $invalidFlow = acif_prepare($pdo, $invitations[$label]);
        if ($invalidState === 'CANCELLED') {
            $pdo->prepare(
                'UPDATE crew_invitations SET invitation_status = \'CANCELLED\', cancelled_at = CURRENT_TIMESTAMP(6) ' .
                'WHERE public_id = :public_id'
            )->execute([':public_id' => $invitations[$label]['public_id']]);
        } elseif ($invalidState === 'EXPIRED') {
            $pdo->prepare(
                'UPDATE crew_invitations SET expires_at = DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 SECOND) ' .
                'WHERE public_id = :public_id'
            )->execute([':public_id' => $invitations[$label]['public_id']]);
        } else {
            $pdo->prepare(
                'UPDATE crew_invitations SET resend_count = resend_count + 1 WHERE public_id = :public_id'
            )->execute([':public_id' => $invitations[$label]['public_id']]);
        }
        acif_with_rollback($pdo, function () use ($pdo, $invalidFlow, $invalidState): void {
            acif_expect_domain(
                fn () => acif_complete(
                    $pdo,
                    $invalidFlow,
                    acif_claims('family-alpha-invalid-' . strtolower($invalidState), 'invalid@example.test'),
                    'family-alpha-invalid-session-' . strtolower($invalidState)
                ),
                'prelaunch_invitation_denied',
                $invalidState . ' invitation admission'
            );
        });
    }

    // An expired Google transaction is retired while its still-current
    // invitation continuation is rebound to fresh state and nonce.
    $staleFlow = acif_prepare($pdo, $invitations['I']);
    $staleId = $pdo->prepare('SELECT id, nonce_hash FROM auth_transactions WHERE public_id = :public_id');
    $staleId->execute([':public_id' => $staleFlow['prepared']['transaction_id']]);
    $staleRow = $staleId->fetch(PDO::FETCH_ASSOC);
    acif_assert(is_array($staleRow), 'Stale Google fixture transaction is missing.');
    $pdo->prepare(
        'UPDATE auth_transactions SET expires_at = DATE_ADD(created_at, INTERVAL 1 MICROSECOND) WHERE id = :id'
    )->execute([':id' => (int) $staleRow['id']]);
    $refreshed = fc_google_refresh_login_transaction(
        $pdo,
        (string) $staleFlow['prepared']['transaction_id'],
        (string) $staleFlow['prepared']['state'],
        (string) $staleFlow['browser_binding'],
        true
    );
    acif_assert(
        !hash_equals((string) $staleFlow['prepared']['transaction_id'], (string) $refreshed['transaction_id'])
            && !hash_equals((string) $staleFlow['prepared']['state'], (string) $refreshed['state'])
            && !hash_equals((string) $staleFlow['prepared']['nonce'], (string) $refreshed['nonce']),
        'Google refresh reused old transaction, state, or nonce.'
    );
    $oldRetired = $pdo->prepare('SELECT consumed_at FROM auth_transactions WHERE id = :id');
    $oldRetired->execute([':id' => (int) $staleRow['id']]);
    acif_assert($oldRetired->fetchColumn() !== null, 'Expired Google transaction was not retired.');
    acif_assert(
        fc_auth_transaction_find_valid(
            $pdo,
            (string) $staleFlow['prepared']['transaction_id'],
            'LOGIN',
            'GOOGLE',
            (string) $staleFlow['prepared']['state'],
            (string) $staleFlow['browser_binding'],
            null
        ) === null,
        'Retired Google transaction remained replayable.'
    );
    $refreshedResult = fc_google_complete_verified_login(
        $pdo,
        (string) $refreshed['transaction_id'],
        (string) $refreshed['state'],
        (string) $staleFlow['browser_binding'],
        acif_claims('family-alpha-refreshed-google', 'refreshed-provider@example.test'),
        'family-alpha-refreshed-session'
    );
    $fixtureUserIds[] = (int) $refreshedResult['user']['id'];
    acif_assert(
        $refreshedResult['destination'] === '/crew-invite.php',
        'Refreshed Google transaction lost the invitation destination.'
    );

    // Stale Auth state cannot refresh cancelled Website authority.
    $cancelledRefreshFlow = acif_prepare($pdo, $invitations['J']);
    $pdo->prepare(
        'UPDATE auth_transactions SET expires_at = DATE_ADD(created_at, INTERVAL 1 MICROSECOND) ' .
        'WHERE public_id = :public_id'
    )->execute([':public_id' => $cancelledRefreshFlow['prepared']['transaction_id']]);
    $pdo->prepare(
        'UPDATE crew_invitations SET invitation_status = \'CANCELLED\', cancelled_at = CURRENT_TIMESTAMP(6) ' .
        'WHERE public_id = :public_id'
    )->execute([':public_id' => $invitations['J']['public_id']]);
    acif_expect_domain(
        fn () => fc_google_refresh_login_transaction(
            $pdo,
            (string) $cancelledRefreshFlow['prepared']['transaction_id'],
            (string) $cancelledRefreshFlow['prepared']['state'],
            (string) $cancelledRefreshFlow['browser_binding'],
            true
        ),
        'invitation_continuation_product_invalid',
        'Cancelled invitation Google refresh'
    );

    $expiredRefreshFlow = acif_prepare($pdo, $invitations['K']);
    $pdo->prepare(
        'UPDATE auth_transactions SET expires_at = DATE_ADD(created_at, INTERVAL 1 MICROSECOND) ' .
        'WHERE public_id = :public_id'
    )->execute([':public_id' => $expiredRefreshFlow['prepared']['transaction_id']]);
    $pdo->prepare(
        'UPDATE crew_invitations SET expires_at = DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 SECOND) ' .
        'WHERE public_id = :public_id'
    )->execute([':public_id' => $invitations['K']['public_id']]);
    acif_expect_domain(
        fn () => fc_google_refresh_login_transaction(
            $pdo,
            (string) $expiredRefreshFlow['prepared']['transaction_id'],
            (string) $expiredRefreshFlow['prepared']['state'],
            (string) $expiredRefreshFlow['browser_binding'],
            true
        ),
        'invitation_continuation_product_invalid',
        'Expired invitation Google refresh'
    );

    $rotatedRefreshFlow = acif_prepare($pdo, $invitations['L']);
    $pdo->prepare(
        'UPDATE auth_transactions SET expires_at = DATE_ADD(created_at, INTERVAL 1 MICROSECOND) ' .
        'WHERE public_id = :public_id'
    )->execute([':public_id' => $rotatedRefreshFlow['prepared']['transaction_id']]);
    $pdo->prepare(
        'UPDATE crew_invitations SET resend_count = resend_count + 1 WHERE public_id = :public_id'
    )->execute([':public_id' => $invitations['L']['public_id']]);
    acif_expect_domain(
        fn () => fc_google_refresh_login_transaction(
            $pdo,
            (string) $rotatedRefreshFlow['prepared']['transaction_id'],
            (string) $rotatedRefreshFlow['prepared']['state'],
            (string) $rotatedRefreshFlow['browser_binding'],
            true
        ),
        'invitation_continuation_product_invalid',
        'Rotated invitation Google refresh'
    );

    // Outside invitation entry, the established Google Family Alpha allowlist still applies.
    fc_auth_crew_invitation_continuation_clear_session();
    acif_with_rollback($pdo, function () use ($pdo): void {
        $state = 'ordinary-prelaunch-denial-state';
        $browser = 'ordinary-prelaunch-denial-browser';
        $transaction = fc_auth_transaction_create(
            $pdo,
            'LOGIN',
            'GOOGLE',
            null,
            $state,
            $browser,
            'APP_HOME',
            'ordinary-prelaunch-denial-nonce'
        );
        acif_expect_domain(
            fn () => fc_google_complete_verified_login(
                $pdo,
                $transaction['public_id'],
                $state,
                $browser,
                acif_claims('ordinary-prelaunch-denied', 'outside-allowlist@example.test'),
                'ordinary-prelaunch-denied-session'
            ),
            'prelaunch_new_account_denied',
            'General non-invitation Google allowlist'
        );
    });

    // Generic rate limiter keeps only keyed evidence and resets its fixed window.
    acif_with_rollback($pdo, function () use ($pdo): void {
        $namespace = 'test.family_alpha_invitation';
        $rawSubject = 'raw-subject-must-not-persist';
        $now = new DateTimeImmutable('2030-01-01 00:00:00', new DateTimeZone('UTC'));
        $first = fc_rate_limit_consume($pdo, $namespace, $rawSubject, 2, 60, 0, $now);
        $second = fc_rate_limit_consume($pdo, $namespace, $rawSubject, 2, 60, 0, $now);
        $third = fc_rate_limit_consume($pdo, $namespace, $rawSubject, 2, 60, 0, $now);
        acif_assert($first['allowed'] && $second['allowed'], 'Allowed rate-limit attempts were denied.');
        acif_assert(!$third['allowed'] && $third['retry_after_seconds'] > 0, 'Excess attempt was allowed.');
        $stored = $pdo->query('SELECT * FROM security_rate_limit_buckets')->fetchAll(PDO::FETCH_ASSOC);
        acif_assert(
            !str_contains(json_encode($stored, JSON_THROW_ON_ERROR), $rawSubject),
            'Rate limiter persisted the raw subject.'
        );
        $reset = fc_rate_limit_consume($pdo, $namespace, $rawSubject, 2, 60, 0, $now->modify('+61 seconds'));
        acif_assert($reset['allowed'], 'Rate-limit window did not reset.');
    });

    acif_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM crew_memberships')->fetchColumn() === $baselineMemberships,
        'Auth created or changed Crew membership.'
    );
    $productTruth = $pdo->prepare(
        'SELECT invitation_status, resend_count FROM crew_invitations WHERE public_id = :public_id'
    );
    foreach (['B', 'C', 'D'] as $unchangedLabel) {
        $productTruth->execute([':public_id' => $invitations[$unchangedLabel]['public_id']]);
        $row = $productTruth->fetch(PDO::FETCH_ASSOC);
        acif_assert(
            is_array($row) && $row['invitation_status'] === 'PENDING' && (int) $row['resend_count'] === 0,
            'Auth mutated Website invitation product state.'
        );
    }
} catch (Throwable $error) {
    $failure = $error;
}

if ($pdo instanceof PDO) {
    try {
        acif_cleanup($pdo, $fixtureInvitationIds, $fixtureCrewId, $fixtureUserIds);
    } catch (Throwable $cleanupError) {
        $failure ??= new RuntimeException('Test cleanup failed: ' . $cleanupError->getMessage(), 0, $cleanupError);
    }
}
fc_auth_crew_invitation_continuation_clear_session();

if ($failure instanceof Throwable) {
    fwrite(STDERR, '[FAIL] ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Family Alpha Auth invitation continuation foundation proof: PASS\n");
fwrite(STDOUT, "- real Website validator / locked new-account admission: PASS\n");
fwrite(STDOUT, "- one new account per logical invitation across continuations and resend generations: PASS\n");
fwrite(STDOUT, "- losing admission creates no user, identity or session: PASS\n");
fwrite(STDOUT, "- rolled-back admission claim permits legitimate retry: PASS\n");
fwrite(STDOUT, "- independent invitation authority / existing-user non-consumption: PASS\n");
fwrite(STDOUT, "- cancelled / expired / rotated invitation rejection: PASS\n");
fwrite(STDOUT, "- stale Google transaction retired / current invitation rebound with fresh state+nonce: PASS\n");
fwrite(STDOUT, "- cancelled / expired / rotated invitations cannot refresh Auth authority: PASS\n");
fwrite(STDOUT, "- minimal Website result / provider-email non-identity: PASS\n");
fwrite(STDOUT, "- rollback-safe consume / committed issue ordering: PASS\n");
fwrite(STDOUT, "- ordinary Google allowlist remains enforced: PASS\n");
fwrite(STDOUT, "- no Crew membership or invitation mutation by Auth: PASS\n");
fwrite(STDOUT, "- generic server-side rate limit / raw-subject non-persistence: PASS\n");
