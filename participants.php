<?php

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/product/bootstrap.php';
fc_require_login();
header('Cache-Control: private, no-store');

$currentUser = fc_current_user();
$userId = (int) $currentUser['user_id'];
$pdo = fc_db();
$appContext = fc_product_context($pdo, $userId);
$crew = $appContext['crew'];
$challenge = $appContext['challenge'];

if (isset($_GET['challenge']) && trim((string) $_GET['challenge']) !== '') {
    try {
        $challenge = fc_challenge_require_public($pdo, $userId, (string) $_GET['challenge']);
        $crew = fc_crew_require_member($pdo, $userId, (int) $challenge['crew_id']);
    } catch (DomainException) {
        fc_response_code(404);
        exit('Challenge is unavailable.');
    }
} elseif (isset($_GET['crew']) && trim((string) $_GET['crew']) !== '') {
    try {
        $crew = fc_crew_require_public($pdo, $userId, (string) $_GET['crew']);
        $challenge = fc_crew_current_challenge($pdo, (int) $crew['id']);
    } catch (DomainException) {
        fc_response_code(404);
        exit('Crew is unavailable.');
    }
}

if ($crew === null) fc_redirect('/crew.php');
$appContext['crew'] = $crew;
$appContext['challenge'] = $challenge;
$returnTo = $challenge !== null
    ? '/participants.php?challenge=' . rawurlencode((string) $challenge['public_id'])
    : '/participants.php?crew=' . rawurlencode((string) $crew['public_id']);

if (fc_is_post()) {
    if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) { fc_response_code(403); exit('Forbidden'); }
    try {
        $action = (string) ($_POST['action'] ?? '');
        $targetCrew = fc_crew_require_public($pdo, $userId, (string) ($_POST['crew_public_id'] ?? $crew['public_id']));
        $targetChallenge = null;
        $challengePublicId = trim((string) ($_POST['challenge_public_id'] ?? ''));
        if ($challengePublicId !== '') {
            $targetChallenge = fc_challenge_require_public($pdo, $userId, $challengePublicId);
            if ((int) $targetChallenge['crew_id'] !== (int) $targetCrew['id']) throw new DomainException('Challenge and Crew context do not match.');
            $returnTo = '/participants.php?challenge=' . rawurlencode((string) $targetChallenge['public_id']);
        } else {
            $returnTo = '/participants.php?crew=' . rawurlencode((string) $targetCrew['public_id']);
        }

        if ($action === 'invite_member') {
            fc_crew_require_owner($pdo, $userId, (int) $targetCrew['id']);
            $current = fc_crew_current_challenge($pdo, (int) $targetCrew['id']);
            if ($current === null) throw new DomainException('Create and publish the Crew’s current Challenge before inviting participants.');
            fc_crew_invitation_rate_limit_issue($pdo, $userId);
            $invitation = fc_crew_invitation_create($pdo, $userId, (int) $targetCrew['id'], (int) $current['id'], (string) ($_POST['email'] ?? ''));
            $delivery = fc_crew_invitation_deliver($pdo, $invitation);
            fc_flash('success', $delivery['driver'] === 'postmark' ? 'Challenge invitation accepted by email transport.' : 'Challenge invitation created. Email delivery is still in log mode.');
        } elseif ($action === 'resend_invitation') {
            fc_crew_require_owner($pdo, $userId, (int) $targetCrew['id']);
            $invitationPublicId = (string) ($_POST['invitation_public_id'] ?? '');
            fc_crew_invitation_rate_limit_resend($pdo, $userId, $invitationPublicId);
            $invitation = fc_crew_invitation_resend($pdo, $userId, (int) $targetCrew['id'], $invitationPublicId);
            $delivery = fc_crew_invitation_deliver($pdo, $invitation);
            fc_flash('success', $delivery['driver'] === 'postmark' ? 'Challenge invitation resend accepted by email transport.' : 'Challenge invitation refreshed. Email delivery is still in log mode.');
        } elseif ($action === 'cancel_invitation') {
            fc_crew_invitation_cancel($pdo, $userId, (int) $targetCrew['id'], (string) ($_POST['invitation_public_id'] ?? ''));
            fc_flash('success', 'Challenge invitation cancelled.');
        } elseif ($action === 'invite') {
            if ($targetChallenge === null) throw new DomainException('Select the current Challenge first.');
            fc_challenge_offer_participation($pdo, $userId, (int) $targetChallenge['id'], (string) ($_POST['member_public_id'] ?? ''));
            fc_flash('success', 'Challenge invitation recorded. The Crew member must personally accept before participating.');
        } elseif ($action === 'cancel_invite') {
            if ($targetChallenge === null) throw new DomainException('Select the current Challenge first.');
            fc_challenge_offer_decide($pdo, $userId, (int) $targetChallenge['id'], 'cancel', (string) ($_POST['offer_public_id'] ?? ''));
            fc_flash('success', 'Pending Challenge invitation cancelled.');
        } elseif ($action === 'remove_participant') {
            if ($targetChallenge === null) throw new DomainException('Select the Challenge first.');
            if (($_POST['confirm_action'] ?? '') !== 'remove_participant') throw new DomainException('Confirm the Challenge removal first.');
            fc_challenge_require_owner($pdo, $userId, (int) $targetChallenge['id']);
            $q = $pdo->prepare('SELECT p.user_id FROM challenge_participations p JOIN users u ON u.id=p.user_id WHERE p.challenge_id=:c AND u.public_id=:u');
            $q->execute([':c' => (int) $targetChallenge['id'], ':u' => (string) ($_POST['member_public_id'] ?? '')]);
            $subject = $q->fetchColumn();
            if ($subject === false) throw new DomainException('Participant is unavailable.');
            fc_challenge_exit_participation($pdo, $userId, (int) $targetChallenge['id'], (int) $subject, 'REMOVED');
            fc_flash('success', 'Challenge participation ended. Crew membership and historical participation remain distinct.');
        } elseif ($action === 'remove_crew_member') {
            if (($_POST['confirm_action'] ?? '') !== 'remove_crew_member') throw new DomainException('Confirm Crew member removal first.');
            fc_crew_membership_remove_public($pdo, $userId, (int) $targetCrew['id'], (string) ($_POST['member_public_id'] ?? ''));
            fc_flash('success', 'Crew member removed. Historical Crew and Challenge records were preserved.');
        } elseif ($action === 'join' || $action === 'withdraw') {
            if ($targetChallenge === null) throw new DomainException('Select the Challenge first.');
            fc_redirect('/participation.php?challenge=' . rawurlencode((string) $targetChallenge['public_id']));
        } else {
            throw new InvalidArgumentException('Unknown People action.');
        }
    } catch (Throwable $error) {
        fc_flash('error', $error instanceof DomainException || $error instanceof InvalidArgumentException ? $error->getMessage() : 'FitCrew could not complete that People action.');
    }
    fc_redirect($returnTo);
}

$currentChallenge = fc_crew_current_challenge($pdo, (int) $crew['id']);
$isCurrentChallenge = $challenge !== null && $currentChallenge !== null && (int) $challenge['id'] === (int) $currentChallenge['id'];
$isOwner = (string) $crew['membership_role'] === 'OWNER';
$memberships = fc_crew_memberships($pdo, $userId, (int) $crew['id']);
$participants = $challenge !== null ? fc_challenge_participants($pdo, $userId, (int) $challenge['id']) : [];
$participation = $challenge !== null ? fc_challenge_participation_for_user($pdo, (int) $challenge['id'], $userId) : null;
$participantContactEmails = $isOwner ? fc_crew_member_contact_email_map($pdo, $userId, (int) $crew['id']) : [];
$pendingInvitations = $isOwner ? fc_crew_invitations_pending($pdo, $userId, (int) $crew['id']) : [];
$pendingOffers = $isOwner && $challenge !== null ? fc_challenge_pending_offers($pdo, $userId, (int) $challenge['id']) : [];

$participantByUser = [];
foreach ($participants as $participantRow) $participantByUser[(string) $participantRow['user_public_id']] = $participantRow;
$inviteableMembers = [];
if ($isOwner && $challenge !== null && $isCurrentChallenge) {
    $q = $pdo->prepare('SELECT u.public_id AS user_public_id,u.display_name FROM crew_memberships m JOIN users u ON u.id=m.user_id WHERE m.crew_id=:c AND m.membership_status=\'ACTIVE\' AND u.account_status=\'ACTIVE\' AND NOT EXISTS (SELECT 1 FROM challenge_participations p WHERE p.challenge_id=:challenge AND p.user_id=m.user_id AND p.participation_status=\'ACTIVE\') ORDER BY u.display_name,u.id');
    $q->execute([':c' => (int) $crew['id'], ':challenge' => (int) $challenge['id']]);
    $inviteableMembers = $q->fetchAll(PDO::FETCH_ASSOC);
}
$activeCrewMemberCount = count(array_filter($memberships, static fn(array $row): bool => (string) $row['membership_status'] === 'ACTIVE'));
$activeParticipantCount = count(array_filter($participants, static fn(array $row): bool => (string) $row['participation_status'] === 'ACTIVE'));

$appSection = $challenge !== null ? 'challenge' : 'crew';
$challengeSection = $challenge !== null ? 'participants' : null;
$title = 'People';
$contentView = 'views/app/challenge/participants.php';
require fc_path('views/layouts/app.php');
