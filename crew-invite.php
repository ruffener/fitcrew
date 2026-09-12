<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/product/bootstrap.php';

$pdo = fc_db();
$token = trim((string) ($_REQUEST['token'] ?? ''));
$invitation = $token !== '' ? fc_crew_invitation_find_token($pdo, $token) : null;

if (fc_is_post()) {
    if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
        fc_response_code(403);
        exit('Forbidden');
    }
    if (!fc_is_logged_in()) {
        fc_flash('notice', 'Sign in first, then return to this invitation to accept it.');
        fc_redirect('/login.php');
    }
    try {
        if ($invitation === null) throw new DomainException('Invitation is unavailable.');
        $currentUser = fc_current_user();
        $crewId = fc_crew_invitation_accept($pdo, (int)$currentUser['user_id'], $token);
        fc_product_context_select_crew($pdo, (int)$currentUser['user_id'], $crewId);
        fc_flash('success', 'Welcome to the Crew.');
        fc_redirect('/crew.php');
    } catch (Throwable $error) {
        fc_flash('error', $error instanceof DomainException || $error instanceof InvalidArgumentException ? $error->getMessage() : 'FitCrew could not accept that invitation.');
        fc_redirect('/crew-invite.php?token=' . rawurlencode($token));
    }
}

$title = 'Crew Invitation';
$contentView = 'views/public/crew_invitation.php';
require fc_path('views/layouts/public.php');
