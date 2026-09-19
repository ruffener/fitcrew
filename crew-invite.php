<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/product/bootstrap.php';

header('Cache-Control: no-store, private');
$pdo = fc_db();
$rawToken = trim((string) ($_GET['token'] ?? ''));

/**
 * Raw bearer landing seam. Validate once, retain only public ID + generation in
 * server-side session state, then immediately leave the token-bearing URL.
 */
if ($rawToken !== '') {
    header('Referrer-Policy: no-referrer');
    try {
        $invitation = fc_crew_invitation_find_token($pdo, $rawToken);
        if ($invitation === null || (string) $invitation['invitation_status'] !== 'PENDING') {
            fc_crew_invitation_rate_limit_invalid_raw($pdo, fc_crew_invitation_client_rate_subject());
            throw new DomainException('This invitation is invalid or no longer current. Open the latest invitation email.');
        }
        if ($invitation['challenge_id'] === null) {
            throw new DomainException('This older Crew-only invitation cannot accept a Challenge. Ask the Crew Owner for a new Challenge invitation.');
        }
        $review = fc_challenge_invitation_review(
            $pdo,
            (string) $invitation['public_id'],
            (int) $invitation['resend_count']
        );
        if ($review === null) {
            fc_crew_invitation_rate_limit_invalid_raw($pdo, fc_crew_invitation_client_rate_subject());
            throw new DomainException('This Challenge invitation changed or expired. Open the latest invitation email.');
        }
        fc_challenge_invitation_review_session_set([
            'invitation_public_id' => (string) $review['invitation_public_id'],
            'generation' => (int) $review['generation'],
        ]);
        fc_challenge_invitation_intent_session_clear();
        fc_redirect('/crew-invite.php');
    } catch (Throwable $error) {
        $message = $error instanceof DomainException || $error instanceof InvalidArgumentException
            ? $error->getMessage()
            : 'FitCrew could not open that Challenge invitation.';
        fc_flash('error', $message);
        fc_redirect('/crew-invite.php');
    }
}

function fc_invitation_requires_fresh_review(string $message): bool
{
    foreach ([
        'Rules changed',
        'privacy terms changed',
        'moved to a different Challenge',
        'invitation changed or expired',
        'invitation is no longer current',
        'Challenge invitation is no longer current',
        'Challenge acceptance expired',
        'Challenge acceptance was already used',
    ] as $needle) {
        if (str_contains($message, $needle)) return true;
    }
    return false;
}

/** @return array{completed:bool,challenge_public_id:?string,already_enrolled:bool} */
function fc_invitation_try_complete(PDO $pdo): array
{
    $currentUser = fc_current_user();
    if ($currentUser === null || fc_challenge_invitation_intent_session_public_id() === null) {
        return ['completed' => false, 'challenge_public_id' => null, 'already_enrolled' => false];
    }

    $consumeAuth = fc_auth_crew_invitation_continuation_current($pdo) !== null;
    $pdo->beginTransaction();
    try {
        $result = fc_challenge_invitation_enroll($pdo, (int) $currentUser['user_id'], $consumeAuth);
        $q = $pdo->prepare('SELECT public_id FROM challenges WHERE id=:id');
        $q->execute([':id' => $result['challenge_id']]);
        $challengePublicId = $q->fetchColumn();
        if ($challengePublicId === false) throw new RuntimeException('Selected Challenge is unavailable.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    fc_challenge_invitation_intent_session_clear();
    fc_challenge_invitation_review_session_clear();
    return [
        'completed' => true,
        'challenge_public_id' => (string) $challengePublicId,
        'already_enrolled' => (bool) $result['already_enrolled'],
    ];
}

// Account switching deliberately resets the PHP session to Auth-owned
// continuation state. Rehydrate the Website review from Auth's minimal current
// continuation after the new account returns.
if (fc_challenge_invitation_review_session_current() === null && fc_is_logged_in()) {
    $resumeContinuation = fc_auth_crew_invitation_continuation_current($pdo);
    if ($resumeContinuation !== null) {
        fc_challenge_invitation_review_session_set([
            'invitation_public_id' => (string) $resumeContinuation['invitation_public_id'],
            'generation' => (int) $resumeContinuation['generation'],
        ]);
        if (fc_challenge_invitation_intent_session_public_id() === null) {
            fc_challenge_invitation_intent_resume_for_invitation(
                $pdo,
                (string) $resumeContinuation['invitation_public_id'],
                (int) $resumeContinuation['generation']
            );
        }
    }
}

if (fc_is_post()) {
    if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
        fc_response_code(403);
        exit('Forbidden');
    }

    try {
        $action = (string) ($_POST['action'] ?? 'accept_challenge');
        if ($action !== 'accept_challenge') throw new DomainException('Unknown invitation action.');
        $review = fc_challenge_invitation_review_current($pdo);
        if ($review === null) throw new DomainException('This Challenge invitation is no longer available. Open the latest invitation email.');
        if (($_POST['accept_contract'] ?? '') !== 'yes') {
            throw new DomainException('Confirm that you accept this Challenge before continuing.');
        }

        $intent = fc_challenge_invitation_intent_create($pdo, $review, [
            'measurements_visibility' => (string) ($_POST['measurements_visibility'] ?? 'PRIVATE'),
            'progress_visibility' => (string) ($_POST['progress_visibility'] ?? 'PRIVATE'),
        ]);
        fc_challenge_invitation_intent_session_set((string) $intent['public_id']);

        if (fc_is_logged_in()) {
            // A signed-in visitor may not yet have an Auth continuation. The
            // product acceptance remains valid without one; account switching
            // uses a separately prepared continuation before this POST.
            $completed = fc_invitation_try_complete($pdo);
            if (!$completed['completed']) throw new RuntimeException('Challenge enrollment did not complete.');
            fc_flash('success', $completed['already_enrolled'] ? 'You are already enrolled in this Challenge.' : 'Welcome to the Challenge.');
            fc_redirect('/challenge.php?view=detail&challenge=' . rawurlencode((string) $completed['challenge_public_id']));
        }

        $auth = fc_auth_crew_invitation_continuation_issue(
            $pdo,
            (string) $review['invitation_public_id'],
            (int) $review['generation'],
            (string) $review['expires_at']
        );
        fc_redirect((string) $auth['next_path']);
    } catch (DomainException $error) {
        $message = $error->getMessage();
        if (fc_invitation_requires_fresh_review($message)) {
            $intentPublicId = fc_challenge_invitation_intent_session_public_id();
            if ($intentPublicId !== null) fc_challenge_invitation_intent_invalidate($pdo, $intentPublicId);
            fc_flash('notice', $message . ' Please review the current Challenge and accept again.');
        } else {
            fc_flash('error', $message);
        }
        fc_redirect('/crew-invite.php');
    } catch (Throwable) {
        fc_flash('error', 'FitCrew could not continue that Challenge invitation.');
        fc_redirect('/crew-invite.php');
    }
}

// Automatic post-auth resume: unchanged accepted terms complete without a
// redundant second Challenge acceptance.
if (fc_is_logged_in() && fc_challenge_invitation_intent_session_public_id() !== null) {
    try {
        $completed = fc_invitation_try_complete($pdo);
        if ($completed['completed']) {
            fc_flash('success', $completed['already_enrolled'] ? 'You are already enrolled in this Challenge.' : 'Welcome to the Challenge.');
            fc_redirect('/challenge.php?view=detail&challenge=' . rawurlencode((string) $completed['challenge_public_id']));
        }
    } catch (DomainException $error) {
        $message = $error->getMessage();
        if (fc_invitation_requires_fresh_review($message)) {
            $intentPublicId = fc_challenge_invitation_intent_session_public_id();
            if ($intentPublicId !== null) fc_challenge_invitation_intent_invalidate($pdo, $intentPublicId);
            fc_flash('notice', $message . ' Please review the current Challenge and accept again.');
        } else {
            fc_flash('error', $message);
        }
    } catch (Throwable) {
        fc_flash('error', 'FitCrew could not complete this Challenge invitation. Please try again.');
    }
}

$review = fc_challenge_invitation_review_current($pdo);
$signedInUser = null;
$signedInEmail = null;
$accountSwitchReady = false;

if ($review !== null && fc_is_logged_in()) {
    $currentUser = fc_current_user();
    if ($currentUser !== null) {
        $signedInUser = fc_user_find_by_id($pdo, (int) $currentUser['user_id'], true);
        $signedInEmail = fc_current_account_email($pdo);

        // Prepare the accepted Auth account-switch path without changing product
        // acceptance. If the user stays with this account, the continuation may
        // be consumed by final enrollment; otherwise Auth preserves invitation
        // authority while switching accounts.
        try {
            $currentContinuation = fc_auth_crew_invitation_continuation_current($pdo);
            if ($currentContinuation === null) {
                fc_auth_crew_invitation_continuation_issue(
                    $pdo,
                    (string) $review['invitation_public_id'],
                    (int) $review['generation'],
                    (string) $review['expires_at']
                );
                fc_auth_crew_invitation_continuation_bind_existing_session($pdo, $currentUser);
            }
            $accountSwitchReady = fc_auth_crew_invitation_continuation_current($pdo) !== null;
        } catch (Throwable) {
            $accountSwitchReady = false;
        }
    }
}

$title = 'Challenge Invitation';
$contentView = 'views/public/crew_invitation.php';
require fc_path('views/layouts/public.php');
