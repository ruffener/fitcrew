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

    try {
        if (fc_product_context_handle_selection($pdo, $userId, $_POST)) {
            fc_flash('success', 'Crew context updated.');
        } elseif (($_POST['action'] ?? '') === 'create_crew') {
            $crew = fc_crew_create(
                $pdo,
                $userId,
                (string) ($_POST['display_name'] ?? ''),
                (string) ($_POST['description'] ?? '')
            );
            fc_product_context_select_crew($pdo, $userId, (int) $crew['id']);
            fc_flash('success', 'Your Crew is ready.');
        }
    } catch (Throwable $error) {
        fc_flash('error', $error instanceof DomainException || $error instanceof InvalidArgumentException
            ? $error->getMessage()
            : 'FitCrew could not complete that Crew action.');
    }

    fc_redirect('/crew.php');
}

$appContext = fc_product_context($pdo, $userId);
$crew = $appContext['crew'];
$memberships = $crew !== null ? fc_crew_memberships($pdo, $userId, (int) $crew['id']) : [];
$crewChallenges = $crew !== null ? fc_challenge_summaries_for_crew($pdo, $userId, (int) $crew['id']) : [];
$appSection = 'crew';
$title = 'Crew';
$contentView = 'views/app/crew/home.php';
require fc_path('views/layouts/app.php');
