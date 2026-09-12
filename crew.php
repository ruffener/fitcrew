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
        } elseif ($action === 'invite_member') {
            $context = fc_product_context($pdo, $userId);
            if ($context['crew'] === null) throw new DomainException('Select a Crew before inviting someone.');
            $invitation = fc_crew_invitation_create($pdo, $userId, (int)$context['crew']['id'], (string)($_POST['email'] ?? ''));
            $delivery = fc_crew_invitation_send_message($invitation);
            fc_flash('success', $delivery['driver'] === 'postmark' ? 'Crew invitation sent.' : 'Crew invitation created. Email delivery is still in log mode.');
        } elseif ($action === 'resend_invitation') {
            $context = fc_product_context($pdo, $userId);
            if ($context['crew'] === null) throw new DomainException('Select a Crew before resending an invitation.');
            $invitation = fc_crew_invitation_resend($pdo, $userId, (int)$context['crew']['id'], (string)($_POST['invitation_public_id'] ?? ''));
            $delivery = fc_crew_invitation_send_message($invitation);
            fc_flash('success', $delivery['driver'] === 'postmark' ? 'Crew invitation resent.' : 'Crew invitation refreshed. Email delivery is still in log mode.');
        } elseif ($action === 'cancel_invitation') {
            $context = fc_product_context($pdo, $userId);
            if ($context['crew'] === null) throw new DomainException('Select a Crew before cancelling an invitation.');
            fc_crew_invitation_cancel($pdo, $userId, (int)$context['crew']['id'], (string)($_POST['invitation_public_id'] ?? ''));
            fc_flash('success', 'Crew invitation cancelled.');
        } elseif ($action === 'remove_member') {
            if (($_POST['confirm_action'] ?? '') !== 'remove_member') throw new DomainException('Confirm Crew member removal first.');
            $targetCrew = fc_crew_require_public($pdo, $userId, (string) ($_POST['crew_public_id'] ?? ''));
            fc_crew_membership_remove_public($pdo, $userId, (int) $targetCrew['id'], (string) ($_POST['member_public_id'] ?? ''));
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
$pendingInvitations = $crew !== null && (string)$crew['membership_role'] === 'OWNER' ? fc_crew_invitations_pending($pdo, $userId, (int)$crew['id']) : [];
$crewChallenges = $crew !== null ? fc_challenge_summaries_for_crew($pdo, $userId, (int) $crew['id']) : [];
$appSection = 'crew';
$title = 'Crew';
$contentView = 'views/app/crew/home.php';
require fc_path('views/layouts/app.php');
