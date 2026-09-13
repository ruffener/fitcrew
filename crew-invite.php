<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/product/bootstrap.php';

$pdo = fc_db();
$rawToken = trim((string) ($_GET['token'] ?? ''));

/*
 * The bearer token exists only at this landing seam. Never render the normal
 * public layout while it is present; validate/exchange and redirect immediately.
 */
if ($rawToken !== '') {
    header('Referrer-Policy: no-referrer');

    try {
        $invitation = fc_crew_invitation_find_token($pdo, $rawToken);
        if ($invitation === null || (string) $invitation['invitation_status'] !== 'PENDING') {
            fc_crew_invitation_rate_limit_invalid_raw($pdo, fc_crew_invitation_client_rate_subject());
            throw new DomainException('This invitation is invalid or no longer current. Open the latest invitation email and try again.');
        }

        $generation = (int) $invitation['resend_count'];
        $snapshot = fc_crew_invitation_auth_snapshot(
            $pdo,
            (string) $invitation['public_id'],
            $generation,
            false
        );
        if ($snapshot === null) {
            fc_crew_invitation_rate_limit_invalid_raw($pdo, fc_crew_invitation_client_rate_subject());
            throw new DomainException('This invitation changed or expired. Open the latest invitation email and try again.');
        }

        $authContinuation = fc_auth_crew_invitation_continuation_issue(
            $pdo,
            (string) $snapshot['invitation_public_id'],
            (int) $snapshot['generation'],
            (string) $snapshot['expires_at']
        );

        fc_redirect((string) $authContinuation['next_path']);
    } catch (Throwable $error) {
        $message = $error instanceof DomainException || $error instanceof InvalidArgumentException
            ? $error->getMessage()
            : 'FitCrew could not continue that invitation.';
        fc_flash('error', $message);
        fc_redirect('/crew-invite.php');
    }
}

if (fc_is_post()) {
    if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
        fc_response_code(403);
        exit('Forbidden');
    }

    if (!fc_is_logged_in()) {
        fc_flash('notice', 'Sign in through the latest invitation link before accepting.');
        fc_redirect('/login.php');
    }

    try {
        $currentUser = fc_current_user();
        if ($currentUser === null) {
            throw new DomainException('Signed-in FitCrew user is unavailable.');
        }

        $continuation = fc_auth_crew_invitation_continuation_current($pdo);
        if ($continuation === null) {
            throw new DomainException('This invitation authentication is no longer available. Open the latest invitation email and try again.');
        }

        if ($pdo->inTransaction()) {
            throw new LogicException('Crew invitation acceptance could not start safely.');
        }

        $pdo->beginTransaction();
        try {
            $crewId = fc_crew_invitation_accept_continuation(
                $pdo,
                (int) $currentUser['user_id'],
                (string) $continuation['invitation_public_id'],
                (int) $continuation['generation']
            );
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        fc_product_context_select_crew($pdo, (int) $currentUser['user_id'], $crewId);
        fc_flash('success', 'Welcome to the Crew.');
        fc_redirect('/crew.php');
    } catch (Throwable $error) {
        $message = $error instanceof DomainException || $error instanceof InvalidArgumentException
            ? $error->getMessage()
            : 'FitCrew could not accept that invitation.';
        fc_flash('error', $message);
        fc_redirect('/crew-invite.php');
    }
}

$continuation = fc_is_logged_in()
    ? fc_auth_crew_invitation_continuation_current($pdo)
    : null;
$invitation = null;
$signedInUser = null;
$alreadyMember = false;

if ($continuation !== null) {
    $currentUser = fc_current_user();
    if ($currentUser !== null) {
        $signedInUser = fc_user_find_by_id($pdo, (int) $currentUser['user_id'], true);
        $invitation = fc_crew_invitation_continuation_display(
            $pdo,
            (string) $continuation['invitation_public_id'],
            (int) $continuation['generation'],
            (int) $currentUser['user_id']
        );
        if ($invitation !== null) {
            $alreadyMember = (string) ($invitation['existing_membership_status'] ?? '') === 'ACTIVE';
        }
    }
}

$title = 'Crew Invitation';
$contentView = 'views/public/crew_invitation.php';
require fc_path('views/layouts/public.php');
