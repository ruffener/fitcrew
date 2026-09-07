<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/product/bootstrap.php';

fc_require_login();
$currentUser = fc_current_user();
$userId = (int) $currentUser['user_id'];
$pdo = fc_db();
$appContext = fc_product_context($pdo, $userId);
$challenge = $appContext['challenge'];
if ($challenge === null) {
    fc_redirect('/challenge.php');
}
$challenge = fc_challenge_require_access($pdo, $userId, (int) $challenge['id']);
$isOwner = (int) $challenge['owner_user_id'] === $userId;

if (fc_is_post()) {
    if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
        fc_response_code(403);
        exit('Forbidden');
    }

    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_draft') {
            fc_challenge_rule_save_draft($pdo, $userId, (int) $challenge['id'], (int) ($_POST['rule_id'] ?? 0), [
                'planned_start_date' => $_POST['planned_start_date'] ?? null,
                'planned_end_date' => $_POST['planned_end_date'] ?? null,
                'duration_days' => (int) ($_POST['duration_days'] ?? 84),
                'challenge_timezone' => (string) ($_POST['challenge_timezone'] ?? ''),
                'weekly_checkin_day' => (int) ($_POST['weekly_checkin_day'] ?? -1),
                'live_leaderboard_visible' => isset($_POST['live_leaderboard_visible']),
            ]);
            fc_flash('success', 'Draft Rules saved. Published history was not changed.');
        } elseif ($action === 'publish') {
            fc_challenge_rule_publish($pdo, $userId, (int) $challenge['id'], (int) ($_POST['rule_id'] ?? 0));
            fc_flash('success', 'Rule Version published.');
        } elseif ($action === 'begin_update') {
            fc_challenge_rule_begin_update($pdo, $userId, (int) $challenge['id']);
            fc_flash('success', 'A new Rule draft is ready. The published version remains unchanged until you publish the update.');
        }
    } catch (Throwable $error) {
        fc_flash('error', $error instanceof DomainException || $error instanceof InvalidArgumentException
            ? $error->getMessage()
            : 'FitCrew could not complete that Rules action.');
    }

    fc_redirect('/rules.php');
}

$appContext = fc_product_context($pdo, $userId);
$challenge = fc_challenge_require_access($pdo, $userId, (int) $appContext['challenge']['id']);
$currentRule = fc_challenge_rule_current_published($pdo, (int) $challenge['id']);
$draftRule = fc_challenge_rule_current_draft($pdo, (int) $challenge['id']);
$ruleHistory = fc_challenge_rule_history($pdo, $userId, (int) $challenge['id']);
$appSection = 'challenge';
$challengeSection = 'rules';
$title = 'Rules';
$contentView = 'views/app/challenge/rules.php';
require fc_path('views/layouts/app.php');
