<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not Found\n");
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/product/bootstrap.php';

function wave1_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wave1_expect_denied(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException($label . ': expected access denial.');
}

$pdo = fc_db();
$requiredTables = ['crews', 'crew_memberships', 'challenges', 'challenge_participations', 'challenge_rule_versions', 'user_product_contexts'];
foreach ($requiredTables as $table) {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
    $statement->execute([':table_name' => $table]);
    wave1_assert((int) $statement->fetchColumn() === 1, 'Wave 1 migration missing table: ' . $table);
}

$pdo->beginTransaction();
try {
    $owner = fc_user_create($pdo, 'Wave1 Owner');
    $member = fc_user_create($pdo, 'Wave1 Member');
    $outsider = fc_user_create($pdo, 'Wave1 Outsider');
    $removable = fc_user_create($pdo, 'Wave1 Removable');
    $platformAdmin = fc_user_create($pdo, 'Wave1 Platform Admin', 'ACTIVE', 'PLATFORM_ADMIN');

    $crew = fc_crew_create($pdo, $owner['id'], 'Wave1 Test Crew', 'Private product-spine proof.');
    $ownerCrew = fc_crew_require_member($pdo, $owner['id'], $crew['id']);
    wave1_assert((string) $ownerCrew['membership_role'] === 'OWNER', 'Crew creator must receive OWNER membership.');

    wave1_expect_denied(fn () => fc_crew_require_member($pdo, $outsider['id'], $crew['id']), 'nonmember Crew read');
    wave1_expect_denied(fn () => fc_crew_require_public($pdo, $outsider['id'], $crew['public_id']), 'changing Crew/public ID must not bypass membership authorization');
    wave1_expect_denied(fn () => fc_crew_memberships($pdo, $outsider['id'], $crew['id']), 'nonmember Crew roster enumeration');
    wave1_expect_denied(fn () => fc_crew_require_member($pdo, $platformAdmin['id'], $crew['id']), 'platform role must not substitute for Crew membership');

    fc_crew_membership_add_existing($pdo, $owner['id'], $crew['id'], $member['id']);
    fc_crew_membership_add_existing($pdo, $owner['id'], $crew['id'], $removable['id']);
    $memberCrew = fc_crew_require_member($pdo, $member['id'], $crew['id']);
    wave1_assert((string) $memberCrew['membership_role'] === 'MEMBER', 'Added Crew user must be a MEMBER.');
    $removableCrew = fc_crew_require_member($pdo, $removable['id'], $crew['id']);
    wave1_assert((string) $removableCrew['membership_role'] === 'MEMBER', 'Test fixture must establish an existing Crew MEMBER.');
    wave1_expect_denied(fn () => fc_crew_membership_add_existing($pdo, $member['id'], $crew['id'], $outsider['id']), 'UI-hidden Crew Owner mutation remains server-blocked');

    wave1_expect_denied(
        fn () => fc_challenge_create($pdo, $member['id'], $crew['id'], 'Unauthorized Challenge', [
            'planned_start_date' => '2026-09-15',
        ]),
        'Crew member cannot create Challenge without Owner authority'
    );

    $challenge = fc_challenge_create($pdo, $owner['id'], $crew['id'], 'Wave1 Challenge', [
        'planned_start_date' => '2026-09-15',
        'planned_end_date' => '2026-12-08',
        'challenge_timezone' => 'America/New_York',
        'weekly_checkin_day' => 6,
        'live_leaderboard_visible' => true,
    ]);

    $challengeRow = fc_challenge_require_access($pdo, $owner['id'], $challenge['id']);
    wave1_assert((string) $challengeRow['lifecycle_status'] === 'DRAFT', 'New Challenge must begin in DRAFT.');

    wave1_expect_denied(fn () => fc_challenge_require_access($pdo, $member['id'], $challenge['id']), 'Crew membership must not imply Challenge access');
    wave1_expect_denied(fn () => fc_challenge_require_public($pdo, $member['id'], $challenge['public_id']), 'changing Challenge/public ID must not bypass participation authorization');
    wave1_expect_denied(fn () => fc_challenge_join($pdo, $outsider['id'], $challenge['id']), 'nonmember cannot join Challenge');

    // Family Alpha requires personal acceptance of a published Rule Version.
    wave1_expect_denied(fn () => fc_challenge_join($pdo, $member['id'], $challenge['id']), 'Legacy join cannot bypass contract acceptance');
    $initialDraft = fc_challenge_rule_current_draft($pdo, $challenge['id']);
    fc_challenge_rule_publish($pdo, $owner['id'], $challenge['id'], (int) $initialDraft['id']);
    fc_challenge_join($pdo, $member['id'], $challenge['id'], (int) $initialDraft['id'], true);
    fc_challenge_join($pdo, $removable['id'], $challenge['id'], (int) $initialDraft['id'], true);
    $memberChallenge = fc_challenge_require_access($pdo, $member['id'], $challenge['id']);
    wave1_assert((int) $memberChallenge['id'] === $challenge['id'], 'Explicit participant must gain Challenge-scoped access.');

    fc_crew_membership_remove($pdo, $owner['id'], $crew['id'], $removable['id']);
    wave1_expect_denied(fn () => fc_crew_require_member($pdo, $removable['id'], $crew['id']), 'removed Crew member must lose Crew access');
    wave1_expect_denied(fn () => fc_challenge_require_access($pdo, $removable['id'], $challenge['id']), 'Crew removal must revoke Challenge access in that Crew');
    $removedParticipation = fc_challenge_participation_for_user($pdo, $challenge['id'], $removable['id']);
    wave1_assert($removedParticipation !== null && (string) $removedParticipation['participation_status'] === 'REMOVED', 'Crew removal must preserve Challenge participation history as REMOVED.');

    $draft = $initialDraft;
    wave1_assert($draft !== null && (int) $draft['version_number'] === 1, 'Challenge creation must establish Rule Version 1 draft.');
    wave1_assert((int) $draft['duration_days'] === 84, 'Planned start/end dates must resolve to the canonical 12-week / 84-day duration.');
    wave1_assert((int) $draft['weekly_checkin_day'] === 6, 'New Challenge setup must preserve the Saturday check-in default.');
    wave1_assert((string) $draft['scoring_standard_code'] === FC_CERTIFIED_SCORING_STANDARD, 'Wave 1 must reference the certified scoring standard without changing it.');

    wave1_expect_denied(
        fn () => fc_challenge_rule_save_draft($pdo, $member['id'], $challenge['id'], (int) $draft['id'], [
            'planned_start_date' => '2026-09-15',
            'duration_days' => 70,
            'challenge_timezone' => 'America/New_York',
            'weekly_checkin_day' => 1,
            'live_leaderboard_visible' => true,
        ]),
        'participant cannot mutate Challenge Rules'
    );

    // Initial publication already occurred before personal acceptance above.
    $published = fc_challenge_rule_current_published($pdo, $challenge['id']);
    wave1_assert($published !== null && (int) $published['version_number'] === 1, 'Rule Version 1 must publish.');

    $afterPublish = fc_challenge_require_access($pdo, $owner['id'], $challenge['id']);
    wave1_assert((string) $afterPublish['lifecycle_status'] === 'FORMING_CREW', 'Initial Rule publication must move Draft to Forming Crew.');
    try {
        fc_challenge_delete_draft($pdo, $owner['id'], $challenge['id']);
        throw new RuntimeException('Published Challenge was hard-deleted.');
    } catch (DomainException) {
        // Published/history-bearing Challenges are not eligible for hard delete.
    }

    $deletable = fc_challenge_create($pdo, $owner['id'], $crew['id'], 'Disposable Draft', [
        'planned_start_date' => '2026-10-01',
    ]);
    wave1_expect_denied(fn () => fc_challenge_delete_draft($pdo, $member['id'], $deletable['id']), 'non-owner cannot delete Challenge Draft');
    fc_challenge_delete_draft($pdo, $owner['id'], $deletable['id']);
    $deletedChallengeCheck = $pdo->prepare('SELECT COUNT(*) FROM challenges WHERE id = :id');
    $deletedChallengeCheck->execute([':id' => $deletable['id']]);
    wave1_assert((int) $deletedChallengeCheck->fetchColumn() === 0, 'Pristine Draft Challenge must be deletable by its Owner.');

    try {
        fc_challenge_rule_save_draft($pdo, $owner['id'], $challenge['id'], (int) $published['id'], [
            'planned_start_date' => '2026-09-15',
            'duration_days' => 84,
            'challenge_timezone' => 'America/New_York',
            'weekly_checkin_day' => 2,
            'live_leaderboard_visible' => true,
        ]);
        throw new RuntimeException('Published Rule Version was silently editable.');
    } catch (DomainException) {
        // Expected immutable published truth.
    }

    $updateDraft = fc_challenge_rule_begin_update($pdo, $owner['id'], $challenge['id']);
    $draftTwo = fc_challenge_rule_current_draft($pdo, $challenge['id']);
    wave1_assert($draftTwo !== null && (int) $draftTwo['id'] === $updateDraft['id'] && (int) $draftTwo['version_number'] === 2, 'Owner change must create a new Rule Version draft.');
    wave1_assert((int) $draftTwo['supersedes_version_id'] === (int) $published['id'], 'New Rule draft must cite the published version it supersedes.');
    $stillPublished = fc_challenge_rule_current_published($pdo, $challenge['id']);
    wave1_assert($stillPublished !== null && (int) $stillPublished['version_number'] === 1, 'Preparing a rule update must preserve current published truth.');

    fc_product_context_select_crew($pdo, $member['id'], $crew['id']);
    $crewOnlyContext = fc_product_context($pdo, $member['id']);
    wave1_assert($crewOnlyContext['challenge'] === null, 'Selecting a Crew must not silently select a Challenge.');
    fc_product_context_select_challenge($pdo, $member['id'], $challenge['id']);
    $context = fc_product_context($pdo, $member['id']);
    wave1_assert((int) $context['crew']['id'] === $crew['id'], 'Selected Crew context must persist.');
    wave1_assert((int) $context['challenge']['id'] === $challenge['id'], 'Selected Challenge context must persist.');
    wave1_expect_denied(fn () => fc_product_context_select_crew($pdo, $outsider['id'], $crew['id']), 'selected context must not grant permission');

    fc_challenge_withdraw($pdo, $member['id'], $challenge['id']);
    $preserved = fc_challenge_participation_for_user($pdo, $challenge['id'], $member['id']);
    wave1_assert($preserved !== null && (string) $preserved['participation_status'] === 'WITHDRAWN' && $preserved['withdrawn_at'] !== null, 'Withdrawal must preserve participation history.');
    $withdrawnAccess = fc_challenge_require_access($pdo, $member['id'], $challenge['id']);
    wave1_assert((int) $withdrawnAccess['id'] === $challenge['id'], 'Withdrawn participant must retain Challenge-history access.');

    $pdo->rollBack();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Wave 1 core product spine proof: PASS\n");
fwrite(STDOUT, "- Crew identity / Owner membership: PASS\n");
fwrite(STDOUT, "- nonmember Crew read + public-ID + roster enumeration denied: PASS\n");
fwrite(STDOUT, "- platform role does not bypass Crew authorization: PASS\n");
fwrite(STDOUT, "- Challenge Owner creation authority: PASS\n");
fwrite(STDOUT, "- Crew membership != Challenge participation / public-ID bypass denied: PASS\n");
fwrite(STDOUT, "- Challenge-scoped participant access: PASS\n");
fwrite(STDOUT, "- nonmember Challenge join denied: PASS\n");
fwrite(STDOUT, "- published Rule Version immutability: PASS\n");
fwrite(STDOUT, "- controlled Rule update draft/history: PASS\n");
fwrite(STDOUT, "- Draft -> Forming Crew governed lifecycle transition: PASS\n");
fwrite(STDOUT, "- selected context is preference, not permission: PASS\n");
fwrite(STDOUT, "- withdrawal preserves participation history + read access: PASS\n");
