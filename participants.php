<?php

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/product/bootstrap.php';
fc_require_login();
header('Cache-Control: private, no-store');
$currentUser=fc_current_user();
$userId=(int)$currentUser['user_id'];
$pdo=fc_db();
$appContext=fc_product_context($pdo,$userId);
$challenge=$appContext['challenge'];
$returnTo='/participants.php';
if (fc_is_post()) {
    if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) { fc_response_code(403); exit('Forbidden'); }
    try {
        // Bind every mutation to the form's Challenge, never to whichever tab changed selected context last.
        $id=fc_family_challenge_id($pdo,(string)($_POST['challenge_public_id'] ?? ''));
        $target=fc_challenge_require_access($pdo,$userId,$id);
        $returnTo='/participants.php?challenge='.rawurlencode((string)$target['public_id']);
        $action=(string)($_POST['action'] ?? '');
        if ($action==='invite') {
            fc_challenge_offer_participation($pdo,$userId,$id,(string)($_POST['member_public_id'] ?? ''));
            fc_flash('success','Invitation recorded. The member must personally accept before participating.');
        } elseif ($action==='cancel_invite') {
            fc_challenge_offer_decide($pdo,$userId,$id,'cancel',(string)($_POST['offer_public_id'] ?? ''));
            fc_flash('success','Pending invitation cancelled.');
        } elseif ($action==='remove') {
            if (($_POST['confirm_action'] ?? '')!=='remove') throw new DomainException('Confirm the participant removal first.');
            fc_challenge_require_owner($pdo,$userId,$id);
            $q=$pdo->prepare('SELECT p.user_id FROM challenge_participations p JOIN users u ON u.id=p.user_id WHERE p.challenge_id=:c AND u.public_id=:u');
            $q->execute([':c'=>$id,':u'=>(string)($_POST['member_public_id'] ?? '')]);
            $subject=$q->fetchColumn();
            if ($subject===false) throw new DomainException('Participant is unavailable.');
            fc_challenge_exit_participation($pdo,$userId,$id,(int)$subject,'REMOVED');
            fc_flash('success','Participant removed. Their participation history and personal privacy rights are preserved.');
        } elseif ($action==='join' || $action==='withdraw') {
            // Old forms cannot bypass the explicit personal review/confirmation surface.
            fc_redirect('/participation.php?challenge='.rawurlencode((string)$target['public_id']));
        } else throw new InvalidArgumentException('Unknown participant action.');
    } catch (Throwable $error) {
        fc_flash('error',$error instanceof DomainException || $error instanceof InvalidArgumentException ? $error->getMessage() : 'FitCrew could not complete that participant action.');
    }
    fc_redirect($returnTo);
}
if (isset($_GET['challenge'])) {
    try { $challenge=fc_challenge_require_public($pdo,$userId,(string)$_GET['challenge']); }
    catch (DomainException) { fc_response_code(404); exit('Challenge is unavailable.'); }
}
if ($challenge===null) fc_redirect('/challenge.php');
$challenge=fc_challenge_require_access($pdo,$userId,(int)$challenge['id']);
$isOwner=(int)$challenge['owner_user_id']===$userId;
$appContext['challenge']=$challenge;
foreach ($appContext['crews'] as $contextCrew) { if ((int)$contextCrew['id']===(int)$challenge['crew_id']) $appContext['crew']=$contextCrew; }
$participants=fc_challenge_participants($pdo,$userId,(int)$challenge['id']);
$participation=fc_challenge_participation_for_user($pdo,(int)$challenge['id'],$userId);
$pendingOffers=$isOwner ? fc_challenge_pending_offers($pdo,$userId,(int)$challenge['id']) : [];
// A Challenge Owner can invite existing Crew members without receiving their account/provider email.
$inviteableMembers=[];
if ($isOwner) {
    $q=$pdo->prepare('SELECT u.public_id AS user_public_id,u.display_name FROM crew_memberships m JOIN users u ON u.id=m.user_id WHERE m.crew_id=:c AND m.membership_status=\'ACTIVE\' AND u.account_status=\'ACTIVE\' AND NOT EXISTS (SELECT 1 FROM challenge_participations p WHERE p.challenge_id=:challenge AND p.user_id=m.user_id AND p.participation_status=\'ACTIVE\') ORDER BY u.display_name,u.id');
    $q->execute([':c'=>(int)$challenge['crew_id'],':challenge'=>(int)$challenge['id']]);$inviteableMembers=$q->fetchAll(PDO::FETCH_ASSOC);
}
$appSection='challenge';$challengeSection='participants';$title='Participants';$contentView='views/app/challenge/participants.php';
require fc_path('views/layouts/app.php');
