<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/product/bootstrap.php';

fc_require_login();
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
            $context = fc_product_context($pdo, $userId);
            if ($context['crew'] === null) {
                throw new DomainException('Create or select a Crew before creating a Challenge.');
            }
            $challenge = fc_challenge_create($pdo, $userId, (int) $context['crew']['id'], (string) ($_POST['display_name'] ?? ''), [
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
            $lookup = $pdo->prepare('SELECT id, crew_id FROM challenges WHERE public_id = :public_id LIMIT 1');
            $lookup->execute([':public_id' => $publicId]);
            $candidate = $lookup->fetch(PDO::FETCH_ASSOC);
            if ($candidate === false) {
                throw new DomainException('Challenge is unavailable.');
            }
            fc_crew_require_member($pdo, $userId, (int) $candidate['crew_id']);
            fc_challenge_join($pdo, $userId, (int) $candidate['id']);
            fc_product_context_select_challenge($pdo, $userId, (int) $candidate['id']);
            fc_flash('success', 'You joined this Challenge.');
            $redirectTo = '/challenge.php?view=detail';
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
$allChallenges = fc_challenges_for_user($pdo, $userId);
$showCreateChallenge = isset($_GET['new']) && $_GET['new'] === '1' && $crew !== null && (string) $crew['membership_role'] === 'OWNER';
$showChallengeDetail = isset($_GET['view']) && $_GET['view'] === 'detail' && $challenge !== null;

$appSection = 'challenge';
$title = 'Challenge';

if ($showChallengeDetail) {
    $participation = fc_challenge_participation_for_user($pdo, (int) $challenge['id'], $userId);
    $currentRule = fc_challenge_rule_current_published($pdo, (int) $challenge['id']);
    $draftRule = fc_challenge_rule_current_draft($pdo, (int) $challenge['id']);
    $challengeSection = 'home';
    $contentView = 'views/app/challenge/home.php';
} else {
    $contentView = 'views/app/challenge/index.php';
}

require fc_path('views/layouts/app.php');
