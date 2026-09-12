<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/product/bootstrap.php';

fc_require_login();
header('Cache-Control: private, no-store');
$currentUser = fc_current_user();
$userId = (int) $currentUser['user_id'];
$pdo = fc_db();

if (fc_is_post()) {
    if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
        fc_response_code(403);
        exit('Forbidden');
    }

    $redirectTo = '/challenge.php';

    try {
        $action = (string) ($_POST['action'] ?? '');

        if (fc_product_context_handle_selection($pdo, $userId, $_POST)) {
            $redirectTo = '/challenge.php?view=detail';
        } elseif ($action === 'prepare_new_challenge') {
            $crew = fc_crew_require_public($pdo, $userId, (string) ($_POST['crew_public_id'] ?? ''));
            fc_crew_require_owner($pdo, $userId, (int) $crew['id']);
            fc_product_context_select_crew($pdo, $userId, (int) $crew['id']);
            $redirectTo = '/challenge.php?new=1';
        } elseif ($action === 'create_challenge') {
            $targetCrew = fc_crew_require_public($pdo, $userId, (string) ($_POST['crew_public_id'] ?? ''));
            $challenge = fc_challenge_create($pdo, $userId, (int) $targetCrew['id'], (string) ($_POST['display_name'] ?? ''), [
                'planned_start_date' => $_POST['planned_start_date'] ?? null,
                'planned_end_date' => $_POST['planned_end_date'] ?? null,
                'duration_days' => (int) ($_POST['duration_days'] ?? 84),
                'challenge_timezone' => (string) ($_POST['challenge_timezone'] ?? fc_config()['timezone']),
                'weekly_checkin_day' => (int) ($_POST['weekly_checkin_day'] ?? 6),
                'live_leaderboard_visible' => isset($_POST['live_leaderboard_visible']),
            ]);
            fc_product_context_select_challenge($pdo, $userId, (int) $challenge['id']);
            fc_redirect('/rules.php');
        } elseif ($action === 'join_challenge') {
            $publicId = trim((string) ($_POST['challenge_public_id'] ?? ''));
            $candidateId = fc_family_challenge_id($pdo, $publicId);
            fc_challenge_personal_context($pdo, $userId, $candidateId);
            $redirectTo = '/participation.php?challenge=' . rawurlencode($publicId);
        } elseif ($action === 'delete_challenge_draft') {
            fc_challenge_delete_draft_public($pdo, $userId, (string) ($_POST['challenge_public_id'] ?? ''));
            fc_flash('success', 'Draft Challenge deleted.');
            $redirectTo = '/challenge.php';
        }
    } catch (Throwable $error) {
        fc_flash('error', $error instanceof DomainException || $error instanceof InvalidArgumentException
            ? $error->getMessage()
            : 'FitCrew could not complete that Challenge action.');
    }

    fc_redirect($redirectTo);
}

$appContext = fc_product_context($pdo, $userId);
$crew = $appContext['crew'];
$challenge = $appContext['challenge'];
if (isset($_GET['challenge']) && ($_GET['view'] ?? '') === 'detail') {
    try {
        $challenge = fc_challenge_require_public($pdo, $userId, (string) $_GET['challenge']);
        $crew = fc_crew_require_member($pdo, $userId, (int) $challenge['crew_id']);
        $appContext['crew'] = $crew;
        $appContext['challenge'] = $challenge;
    } catch (DomainException) { fc_response_code(404); exit('Challenge is unavailable.'); }
}
$showHistory = ($_GET['show'] ?? '') === 'history';
$allChallenges = fc_challenges_for_user($pdo, $userId, null, $showHistory);
$personalChallenges = fc_challenge_personal_list($pdo, $userId);
$showCreateChallenge = isset($_GET['new']) && $_GET['new'] === '1' && $crew !== null && (string) $crew['membership_role'] === 'OWNER';
$showChallengeDetail = isset($_GET['view']) && $_GET['view'] === 'detail' && $challenge !== null;

$appSection = 'challenge';
$title = 'Challenge';

if ($showChallengeDetail) {
    $management = fc_challenge_management_state($pdo, (int) $challenge['id']);
    $participation = fc_challenge_participation_for_user($pdo, (int) $challenge['id'], $userId);
    $currentRule = fc_challenge_rule_current_published($pdo, (int) $challenge['id']);
    $acceptanceQuery = $pdo->prepare('SELECT rule_version_id FROM challenge_acceptance_records WHERE challenge_id=:c AND user_id=:u ORDER BY id DESC LIMIT 1');
    $acceptanceQuery->execute([':c'=>(int)$challenge['id'], ':u'=>$userId]);
    $acceptedRuleId = $acceptanceQuery->fetchColumn();
    $personalAcceptanceNeeded = $currentRule !== null && $participation !== null && $participation['participation_status'] === 'ACTIVE' && ($acceptedRuleId === false || (int)$acceptedRuleId !== (int)$currentRule['id']);
    $draftRule = fc_challenge_rule_current_draft($pdo, (int) $challenge['id']);
    $challengeSection = 'home';
    $contentView = 'views/app/challenge/home.php';
} else {
    $contentView = 'views/app/challenge/index.php';
}

require fc_path('views/layouts/app.php');
