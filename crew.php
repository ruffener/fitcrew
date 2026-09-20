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

    $redirectTo = '/crew.php';

    try {
        $action = (string) ($_POST['action'] ?? '');
        $selectedCrewPublicId = trim((string) ($_POST['select_crew'] ?? ''));

        if (fc_product_context_handle_selection($pdo, $userId, $_POST)) {
            if ($selectedCrewPublicId !== '') {
                $redirectTo = '/crew.php?crew=' . rawurlencode($selectedCrewPublicId);
            }
        } elseif ($action === 'create_crew') {
            $crew = fc_crew_create(
                $pdo,
                $userId,
                (string) ($_POST['display_name'] ?? ''),
                (string) ($_POST['description'] ?? '')
            );
            fc_product_context_select_crew($pdo, $userId, (int) $crew['id']);
            fc_flash('success', 'Your Crew is ready.');
            $redirectTo = '/crew.php?crew=' . rawurlencode((string) $crew['public_id']);
        } elseif ($action === 'invite_member') {
            $context = fc_product_context($pdo, $userId);
            if ($context['crew'] === null) throw new DomainException('Select a Crew before inviting someone.');
            $currentChallenge = fc_crew_current_challenge($pdo, (int) $context['crew']['id']);
            if ($currentChallenge === null) throw new DomainException('Create and publish the Crew’s current Challenge before inviting participants.');
            fc_crew_invitation_rate_limit_issue($pdo, $userId);
            $invitation = fc_crew_invitation_create(
                $pdo,
                $userId,
                (int) $context['crew']['id'],
                (int) $currentChallenge['id'],
                (string) ($_POST['email'] ?? '')
            );
            $delivery = fc_crew_invitation_deliver($pdo, $invitation);
            fc_flash('success', $delivery['driver'] === 'postmark'
                ? 'Challenge invitation accepted by email transport.'
                : 'Challenge invitation created. Email delivery is still in log mode.');
            $redirectTo = '/crew.php?crew=' . rawurlencode((string) $context['crew']['public_id']);
        } elseif ($action === 'resend_invitation') {
            $context = fc_product_context($pdo, $userId);
            if ($context['crew'] === null) throw new DomainException('Select a Crew before resending an invitation.');
            $invitationPublicId = (string)($_POST['invitation_public_id'] ?? '');
            fc_crew_invitation_rate_limit_resend($pdo, $userId, $invitationPublicId);
            $invitation = fc_crew_invitation_resend($pdo, $userId, (int)$context['crew']['id'], $invitationPublicId);
            $delivery = fc_crew_invitation_deliver($pdo, $invitation);
            fc_flash('success', $delivery['driver'] === 'postmark'
                ? 'Crew invitation resend accepted by email transport.'
                : 'Crew invitation refreshed. Email delivery is still in log mode.');
            $redirectTo = '/crew.php?crew=' . rawurlencode((string) $context['crew']['public_id']);
        } elseif ($action === 'cancel_invitation') {
            $context = fc_product_context($pdo, $userId);
            if ($context['crew'] === null) throw new DomainException('Select a Crew before cancelling an invitation.');
            fc_crew_invitation_cancel($pdo, $userId, (int)$context['crew']['id'], (string)($_POST['invitation_public_id'] ?? ''));
            fc_flash('success', 'Crew invitation cancelled.');
            $redirectTo = '/crew.php?crew=' . rawurlencode((string) $context['crew']['public_id']);
        } elseif ($action === 'remove_member') {
            if (($_POST['confirm_action'] ?? '') !== 'remove_member') throw new DomainException('Confirm Crew member removal first.');
            $targetCrew = fc_crew_require_public($pdo, $userId, (string) ($_POST['crew_public_id'] ?? ''));
            fc_crew_membership_remove_public($pdo, $userId, (int) $targetCrew['id'], (string) ($_POST['member_public_id'] ?? ''));
            fc_flash('success', 'Crew member removed. History was preserved.');
            $redirectTo = '/crew.php?crew=' . rawurlencode((string) $targetCrew['public_id']);
        }
    } catch (Throwable $error) {
        fc_flash('error', $error instanceof DomainException || $error instanceof InvalidArgumentException
            ? $error->getMessage()
            : 'FitCrew could not complete that Crew action.');
    }

    fc_redirect($redirectTo);
}

$appContext = fc_product_context($pdo, $userId);
$showCrewList = !isset($_GET['crew']) || trim((string) $_GET['crew']) === '';
$crew = $appContext['crew'];

if (!$showCrewList) {
    try {
        $crew = fc_crew_require_public($pdo, $userId, (string) $_GET['crew']);
        $appContext['crew'] = $crew;
        $appContext['challenge'] = fc_crew_current_challenge($pdo, (int) $crew['id']);
    } catch (DomainException) {
        fc_response_code(404);
        exit('Crew is unavailable.');
    }
}

$crewListCurrentChallenges = [];
foreach ($appContext['crews'] as $contextCrew) {
    $crewListCurrentChallenges[(int) $contextCrew['id']] = fc_crew_current_challenge($pdo, (int) $contextCrew['id']);
}

$currentChallenge = !$showCrewList && $crew !== null ? fc_crew_current_challenge($pdo, (int) $crew['id']) : null;
$memberships = !$showCrewList && $crew !== null ? fc_crew_memberships($pdo, $userId, (int) $crew['id']) : [];
$memberContactEmails = !$showCrewList && $crew !== null && (string) $crew['membership_role'] === 'OWNER'
    ? fc_crew_member_contact_email_map($pdo, $userId, (int) $crew['id'])
    : [];
$pendingInvitations = !$showCrewList && $crew !== null && (string)$crew['membership_role'] === 'OWNER'
    ? fc_crew_invitations_pending($pdo, $userId, (int)$crew['id'])
    : [];
$crewChallenges = !$showCrewList && $crew !== null ? fc_challenge_summaries_for_crew($pdo, $userId, (int) $crew['id']) : [];
$signedInEmail = fc_current_account_email($pdo);
$appSection = 'crew';
$title = 'Crew';
$contentView = 'views/app/crew/home.php';
require fc_path('views/layouts/app.php');
