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

if (fc_is_post()) {
    if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
        fc_response_code(403);
        exit('Forbidden');
    }

    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'join') {
            fc_challenge_join($pdo, $userId, (int) $challenge['id']);
            fc_product_context_select_challenge($pdo, $userId, (int) $challenge['id']);
            fc_flash('success', 'You joined this Challenge.');
        } elseif ($action === 'withdraw') {
            fc_challenge_withdraw($pdo, $userId, (int) $challenge['id']);
            fc_flash('success', 'Your Challenge participation is now withdrawn. Your participation history was preserved.');
        }
    } catch (Throwable $error) {
        fc_flash('error', $error instanceof DomainException || $error instanceof InvalidArgumentException
            ? $error->getMessage()
            : 'FitCrew could not complete that participation action.');
    }

    fc_redirect('/participants.php');
}

// A Crew member may see a Crew-safe Challenge teaser in Crew Home, but the full
// participant roster remains Challenge-scoped and requires Owner/participant access.
$challenge = fc_challenge_require_access($pdo, $userId, (int) $challenge['id']);
$participants = fc_challenge_participants($pdo, $userId, (int) $challenge['id']);
$participation = fc_challenge_participation_for_user($pdo, (int) $challenge['id'], $userId);
$appSection = 'challenge';
$challengeSection = 'participants';
$title = 'Participants';
$contentView = 'views/app/challenge/participants.php';
require fc_path('views/layouts/app.php');
