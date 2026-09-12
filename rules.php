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
if (fc_is_post()) {
    if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) { fc_response_code(403); exit('Forbidden'); }
    $redirect='/rules.php';
    try {
        // Every posted form names its Challenge, so changing a different tab cannot retarget a write.
        $challenge=fc_challenge_require_public($pdo,$userId,(string)($_POST['challenge_public_id'] ?? ''));
        $redirect='/rules.php?challenge='.rawurlencode((string)$challenge['public_id']);
        $action=(string)($_POST['action'] ?? '');
        if ($action==='open_challenge_rules') {
            fc_challenge_require_owner($pdo,$userId,(int)$challenge['id']);
            fc_product_context_select_challenge($pdo,$userId,(int)$challenge['id']);
        } elseif ($action==='save_draft') {
            fc_challenge_rule_save_draft($pdo,$userId,(int)$challenge['id'],(int)($_POST['rule_id'] ?? 0),[
                'planned_start_date'=>$_POST['planned_start_date'] ?? null,
                'planned_end_date'=>$_POST['planned_end_date'] ?? null,
                'duration_days'=>(int)($_POST['duration_days'] ?? 84),
                'challenge_timezone'=>(string)($_POST['challenge_timezone'] ?? ''),
                'weekly_checkin_day'=>(int)($_POST['weekly_checkin_day'] ?? -1),
                'live_leaderboard_visible'=>isset($_POST['live_leaderboard_visible']),
            ]);
            fc_flash('success','Draft Rules saved. Published history and individual acceptance are unchanged.');
        } elseif ($action==='publish') {
            fc_challenge_rule_publish($pdo,$userId,(int)$challenge['id'],(int)($_POST['rule_id'] ?? 0));
            fc_flash('success','Rule Version published. Earlier versions and personal acceptance receipts are preserved.');
        } elseif ($action==='begin_update') {
            fc_challenge_rule_begin_update($pdo,$userId,(int)$challenge['id']);
            fc_flash('success','A new Rule draft is ready. The current published version stays unchanged until you publish.');
        } else throw new InvalidArgumentException('Unknown Rules action.');
    } catch (Throwable $error) {
        fc_flash('error',$error instanceof DomainException || $error instanceof InvalidArgumentException ? $error->getMessage() : 'FitCrew could not complete that Rules action.');
    }
    fc_redirect($redirect);
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
$currentRule=fc_challenge_rule_current_published($pdo,(int)$challenge['id']);
$draftRule=$isOwner ? fc_challenge_rule_current_draft($pdo,(int)$challenge['id']) : null;
$ruleHistory=fc_challenge_rule_history($pdo,$userId,(int)$challenge['id']);
$appSection='challenge';$challengeSection='rules';$title='Rules';$contentView='views/app/challenge/rules.php';
require fc_path('views/layouts/app.php');
