<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/product/bootstrap.php';
fc_require_login();
header('Cache-Control: private, no-store');
$currentUser = fc_current_user();
$userId = (int) $currentUser['user_id'];
$pdo = fc_db();
$publicId = trim((string) (fc_is_post() ? ($_POST['challenge_public_id'] ?? '') : ($_GET['challenge'] ?? '')));
try {
    $challengeId = fc_family_challenge_id($pdo, $publicId);
    $personal = fc_challenge_personal_context($pdo, $userId, $challengeId);
} catch (DomainException) {
    fc_response_code(404);
    exit('Challenge is unavailable.');
}
if (fc_is_post()) {
    if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
        fc_response_code(403);
        exit('Forbidden');
    }
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'accept') {
            fc_challenge_accept_participation(
                $pdo, $userId, $challengeId, (int) ($_POST['rule_version_id'] ?? 0),
                ($_POST['accept_contract'] ?? '') === 'yes',
                ['measurements_visibility' => $_POST['measurements_visibility'] ?? 'PRIVATE', 'progress_visibility' => $_POST['progress_visibility'] ?? 'PRIVATE'],
                isset($_POST['offer_public_id']) ? (string) $_POST['offer_public_id'] : null
            );
            fc_flash('success', 'Your personal acceptance is recorded. You are participating in this Challenge.');
        } elseif ($action === 'privacy') {
            fc_challenge_privacy_save($pdo, $userId, $challengeId, [
                'measurements_visibility' => $_POST['measurements_visibility'] ?? 'PRIVATE',
                'progress_visibility' => $_POST['progress_visibility'] ?? 'PRIVATE',
            ]);
            fc_flash('success', 'Your sharing choices are saved for this Challenge.');
        } elseif ($action === 'withdraw') {
            if (($_POST['confirm_action'] ?? '') !== 'withdraw') throw new DomainException('Confirm your withdrawal first.');
            fc_challenge_withdraw($pdo, $userId, $challengeId);
            fc_flash('success', 'You have withdrawn. Your Crew membership, account, and history are unchanged.');
        } elseif ($action === 'decline') {
            fc_challenge_offer_decide($pdo, $userId, $challengeId, 'decline', (string) ($_POST['offer_public_id'] ?? ''));
            fc_flash('success', 'Invitation declined. No participation was created.');
        } else {
            throw new InvalidArgumentException('Unknown participation action.');
        }
    } catch (Throwable $error) {
        fc_flash('error', $error instanceof DomainException || $error instanceof InvalidArgumentException
            ? $error->getMessage() : 'FitCrew could not save that choice. No successful change is confirmed.');
    }
    fc_redirect('/participation.php?challenge=' . rawurlencode($publicId));
}
$personal = fc_challenge_personal_context($pdo, $userId, $challengeId);
$challenge = $personal['challenge'];
$privacy = $personal['privacy'];
$participation = $personal['participation'];
$currentRule = $personal['rule'];
$management = $personal['management'];
$offer = $personal['offer'];
$history = $pdo->prepare('SELECT entered_at,exited_at,exit_status,entry_source FROM challenge_participation_intervals WHERE challenge_id=:c AND user_id=:u ORDER BY id');
$history->execute([':c' => $challengeId, ':u' => $userId]);
$myIntervals = $history->fetchAll(PDO::FETCH_ASSOC);
$consent = $pdo->prepare('SELECT rule_version_id,accepted_at,contract_code FROM challenge_acceptance_records WHERE challenge_id=:c AND user_id=:u ORDER BY id DESC LIMIT 1');
$consent->execute([':c' => $challengeId, ':u' => $userId]);
$myAcceptance = $consent->fetch(PDO::FETCH_ASSOC) ?: null;
$appContext = fc_product_context($pdo, $userId);
$appSection = 'challenge';
$title = 'My participation & privacy';
$contentView = 'views/app/challenge/participation.php';
require fc_path('views/layouts/app.php');
