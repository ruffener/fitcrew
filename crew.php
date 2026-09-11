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
        $action = (string) ($_POST['action'] ?? '');
        if (fc_product_context_handle_selection($pdo, $userId, $_POST)) {
            // Choosing a Crew is normal navigation, not a success event.
        } elseif ($action === 'create_crew') {
            $crew = fc_crew_create(
                $pdo,
                $userId,
                (string) ($_POST['display_name'] ?? ''),
                (string) ($_POST['description'] ?? '')
            );
            fc_product_context_select_crew($pdo, $userId, (int) $crew['id']);
            fc_flash('success', 'Your Crew is ready.');
        } elseif ($action === 'add_member') {
            $context = fc_product_context($pdo, $userId);
            if ($context['crew'] === null) {
                throw new DomainException('Select a Crew before adding a member.');
            }
            fc_crew_membership_add_public(
                $pdo,
                $userId,
                (int) $context['crew']['id'],
                (string) ($_POST['member_public_id'] ?? '')
            );
            fc_flash('success', 'Crew member added.');
        } elseif ($action === 'remove_member') {
            $context = fc_product_context($pdo, $userId);
            if ($context['crew'] === null) {
                throw new DomainException('Select a Crew before removing a member.');
            }
            fc_crew_membership_remove_public(
                $pdo,
                $userId,
                (int) $context['crew']['id'],
                (string) ($_POST['member_public_id'] ?? '')
            );
            fc_flash('success', 'Crew member removed. History was preserved.');
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
