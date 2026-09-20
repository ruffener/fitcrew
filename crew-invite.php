<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/product/bootstrap.php';

const FC_INVITATION_PROFILE_REQUIRED_SESSION_KEY = 'fitcrew_invitation_email_profile_required';

header('Cache-Control: no-store, private');
$pdo = fc_db();
$rawQueryToken = trim((string) ($_GET['token'] ?? ''));

/**
 * New invitation credentials live in the URL fragment. Query-token links are
 * deliberately legacy-only and are never upgraded into EMAIL login proof.
 */
if ($rawQueryToken !== '') {
    header('Referrer-Policy: no-referrer');
    try {
        $invitation = fc_crew_invitation_find_token($pdo, $rawQueryToken);
        if ($invitation === null || (string) $invitation['invitation_status'] !== 'PENDING') {
            fc_crew_invitation_rate_limit_invalid_raw($pdo, fc_crew_invitation_client_rate_subject());
            throw new DomainException('This invitation is invalid or no longer current. Open the latest invitation email.');
        }
        if ($invitation['challenge_id'] === null) {
            throw new DomainException('This older Crew-only invitation cannot accept a Challenge. Ask the Crew Owner for a new Challenge invitation.');
        }
        throw new DomainException('This invitation uses an older sign-in link. Ask the Crew Owner to resend it, then open the newest email.');
    } catch (Throwable $error) {
        $message = $error instanceof DomainException || $error instanceof InvalidArgumentException
            ? $error->getMessage()
            : 'FitCrew could not open that Challenge invitation.';
        fc_flash('error', $message);
        fc_redirect('/crew-invite.php?empty=1');
    }
}

function fc_invitation_profile_required_set(bool $required): void
{
    if ($required) {
        $_SESSION[FC_INVITATION_PROFILE_REQUIRED_SESSION_KEY] = true;
        return;
    }
    unset($_SESSION[FC_INVITATION_PROFILE_REQUIRED_SESSION_KEY]);
}

function fc_invitation_profile_required(): bool
{
    return ($_SESSION[FC_INVITATION_PROFILE_REQUIRED_SESSION_KEY] ?? false) === true;
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

/** @return array{completed:bool,already_enrolled:bool,user_id:?int,new_account:bool} */
function fc_invitation_complete_email_proof(
    PDO $pdo,
    array $emailContext,
    string $csrfToken,
    ?string $displayName = null
): array {
    $intent = fc_challenge_invitation_intent_current($pdo);
    if ($intent === null) {
        throw new DomainException('Your Challenge acceptance expired. Review the invitation again.');
    }

    $pdo->beginTransaction();
    try {
        $auth = fc_auth_invitation_email_complete(
            $pdo,
            (string) $emailContext['proof_public_id'],
            (string) $emailContext['invitation_public_id'],
            (int) $emailContext['generation'],
            $csrfToken,
            $displayName
        );
        $result = fc_challenge_invitation_enroll($pdo, (int) $auth['user_id'], false);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    fc_challenge_invitation_intent_session_clear();
    fc_challenge_invitation_review_session_clear();
    fc_invitation_profile_required_set(false);

    return [
        'completed' => true,
        'already_enrolled' => (bool) $result['already_enrolled'],
        'user_id' => (int) $auth['user_id'],
        'new_account' => (bool) $auth['new_account'],
    ];
}

/**
 * Secondary path only: the recipient explicitly chose an ordinary FitCrew
 * account under another email. That path retains the accepted Auth
 * continuation and uses the authenticated canonical user returned by Auth.
 * @return array{completed:bool,already_enrolled:bool}
 */
function fc_invitation_complete_authenticated_continuation(PDO $pdo): array
{
    $currentUser = fc_current_user();
    $continuation = fc_auth_crew_invitation_continuation_current($pdo);
    if ($currentUser === null || $continuation === null || fc_challenge_invitation_intent_session_public_id() === null) {
        return ['completed' => false, 'already_enrolled' => false];
    }

    $pdo->beginTransaction();
    try {
        $result = fc_challenge_invitation_enroll($pdo, (int) $currentUser['user_id'], true);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    fc_challenge_invitation_intent_session_clear();
    fc_challenge_invitation_review_session_clear();
    fc_invitation_profile_required_set(false);
    return ['completed' => true, 'already_enrolled' => (bool) $result['already_enrolled']];
}

function fc_invitation_audit_auth_rejection(PDO $pdo, Throwable $error): void
{
    try {
        fc_auth_invitation_email_audit_rejection($pdo, $error);
    } catch (Throwable) {
        // Rejection auditing must never turn a safe denial into a second failure.
    }
}

/**
 * The browser receives the EMAIL credential only in the fragment. Strip it
 * from history before any external resource loads, then exchange it through a
 * same-origin CSRF-protected POST. A scanner can perform this capture without
 * authenticating or consuming the invitation.
 */
if (!fc_is_post()
    && fc_challenge_invitation_review_session_current() === null
    && !isset($_GET['empty'])) {
    header('Referrer-Policy: no-referrer');
    $csrf = fc_csrf_token();
    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>FitCrew Challenge Invitation</title>
    <style>
        body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f4f6f8;color:#13233a;margin:0;display:grid;min-height:100vh;place-items:center}
        main{max-width:42rem;padding:2rem;text-align:center}strong{display:block;font-size:1.2rem;margin-bottom:.5rem}p{line-height:1.55;color:#5e6878}
    </style>
</head>
<body>
<main id="invite-status" aria-live="polite">
    <strong>Opening your FitCrew Challenge invitation…</strong>
    <p>Reviewing an invitation does not sign you in or join the Challenge.</p>
</main>
<script>
(() => {
    const params = new URLSearchParams(window.location.hash.slice(1));
    const token = params.get('token') || '';
    history.replaceState(null, document.title, window.location.pathname);
    if (!token) {
        window.location.replace('/crew-invite.php?empty=1');
        return;
    }
    const form = document.createElement('form');
    form.method = 'post';
    form.action = '/crew-invite.php';
    for (const [name, value] of Object.entries({
        action: 'capture_invitation_email',
        csrf_token: <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        raw_token: token
    })) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
})();
</script>
</body>
</html><?php
    exit;
}

// Explicit account switching may reset Website session state. Rehydrate the
// invitation and any already accepted intent from Auth's fixed continuation.
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

    $action = (string) ($_POST['action'] ?? 'accept_challenge');

    if ($action === 'capture_invitation_email') {
        header('Referrer-Policy: no-referrer');
        try {
            $capture = fc_auth_invitation_email_capture(
                $pdo,
                (string) ($_POST['raw_token'] ?? ''),
                (string) ($_POST['csrf_token'] ?? '')
            );
            $review = fc_challenge_invitation_review(
                $pdo,
                (string) $capture['invitation_public_id'],
                (int) $capture['generation']
            );
            if ($review === null) {
                throw new DomainException('This Challenge invitation changed or expired. Open the latest invitation email.');
            }
            fc_challenge_invitation_review_session_set([
                'invitation_public_id' => (string) $capture['invitation_public_id'],
                'generation' => (int) $capture['generation'],
            ]);
            fc_challenge_invitation_intent_session_clear();
            fc_invitation_profile_required_set(false);
            fc_redirect('/crew-invite.php');
        } catch (Throwable $error) {
            try {
                fc_crew_invitation_rate_limit_invalid_raw($pdo, fc_crew_invitation_client_rate_subject());
            } catch (Throwable) {
            }
            $message = $error instanceof DomainException || $error instanceof InvalidArgumentException
                ? $error->getMessage()
                : 'FitCrew could not open that Challenge invitation.';
            fc_flash('error', $message);
            fc_redirect('/crew-invite.php?empty=1');
        }
    }

    try {
        $review = fc_challenge_invitation_review_current($pdo);
        if ($review === null) {
            throw new DomainException('This Challenge invitation is no longer available. Open the latest invitation email.');
        }

        if ($action === 'use_different_account') {
            $auth = fc_auth_crew_invitation_continuation_issue(
                $pdo,
                (string) $review['invitation_public_id'],
                (int) $review['generation'],
                (string) $review['expires_at']
            );
            if (fc_is_logged_in()) {
                $currentUser = fc_current_user();
                if ($currentUser === null) throw new DomainException('Your current FitCrew session is unavailable.');
                fc_auth_crew_invitation_continuation_bind_existing_session($pdo, $currentUser);
                ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Switch FitCrew account</title></head><body>
                <form id="fitcrew-switch" method="post" action="/auth/invitation/switch-account.php">
                    <?= fc_csrf_input() ?>
                    <button type="submit">Continue to account selection</button>
                </form>
                <script>document.getElementById('fitcrew-switch').submit();</script>
                </body></html><?php
                exit;
            }
            fc_redirect((string) $auth['next_path']);
        }

        if ($action === 'complete_profile') {
            if (fc_challenge_invitation_intent_current($pdo) === null) {
                throw new DomainException('Your Challenge acceptance expired. Review the invitation again.');
            }
            $emailContext = fc_auth_invitation_email_context($pdo);
            $completed = fc_invitation_complete_email_proof(
                $pdo,
                $emailContext,
                (string) ($_POST['csrf_token'] ?? ''),
                (string) ($_POST['display_name'] ?? '')
            );
            fc_flash('success', $completed['already_enrolled'] ? 'You are already enrolled in this Challenge.' : 'Welcome to FitCrew Challenge.');
            fc_redirect('/app.php');
        }

        if ($action !== 'accept_challenge') throw new DomainException('Unknown invitation action.');
        if (($_POST['accept_contract'] ?? '') !== 'yes') {
            throw new DomainException('Confirm that you accept this Challenge before continuing.');
        }

        $intent = fc_challenge_invitation_intent_create($pdo, $review, [
            'measurements_visibility' => (string) ($_POST['measurements_visibility'] ?? 'PRIVATE'),
            'progress_visibility' => (string) ($_POST['progress_visibility'] ?? 'PRIVATE'),
        ]);
        fc_challenge_invitation_intent_session_set((string) $intent['public_id']);
        fc_invitation_profile_required_set(false);

        try {
            $emailContext = fc_auth_invitation_email_context($pdo);
        } catch (DomainException) {
            $emailContext = null;
        }

        if ($emailContext !== null) {
            try {
                $completed = fc_invitation_complete_email_proof(
                    $pdo,
                    $emailContext,
                    (string) ($_POST['csrf_token'] ?? '')
                );
                fc_flash('success', $completed['already_enrolled'] ? 'You are already enrolled in this Challenge.' : 'Welcome to the Challenge.');
                fc_redirect('/app.php');
            } catch (DomainException $error) {
                if ($error->getMessage() === 'invitation_email_profile_required') {
                    fc_invitation_profile_required_set(true);
                    fc_redirect('/crew-invite.php');
                }
                if ($error->getMessage() === 'invitation_email_account_switch_required') {
                    fc_flash('notice', 'This invitation email belongs to a different FitCrew account. Use a different account to continue.');
                    fc_redirect('/crew-invite.php');
                }
                fc_invitation_audit_auth_rejection($pdo, $error);
                throw $error;
            }
        }

        // Secondary path only: the participant explicitly chose ordinary Auth
        // because their FitCrew account uses another email.
        if (fc_is_logged_in() && fc_auth_crew_invitation_continuation_current($pdo) !== null) {
            $completed = fc_invitation_complete_authenticated_continuation($pdo);
            if (!$completed['completed']) throw new RuntimeException('Challenge enrollment did not complete.');
            fc_flash('success', $completed['already_enrolled'] ? 'You are already enrolled in this Challenge.' : 'Welcome to the Challenge.');
            fc_redirect('/app.php');
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
            fc_invitation_profile_required_set(false);
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

// Automatic resume exists only for the explicit ordinary-account path. The
// normal invitation-email path authenticates and enrolls inside the Accept POST.
if (fc_is_logged_in()
    && fc_challenge_invitation_intent_session_public_id() !== null
    && fc_auth_crew_invitation_continuation_current($pdo) !== null) {
    try {
        $completed = fc_invitation_complete_authenticated_continuation($pdo);
        if ($completed['completed']) {
            fc_flash('success', $completed['already_enrolled'] ? 'You are already enrolled in this Challenge.' : 'Welcome to the Challenge.');
            fc_redirect('/app.php');
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
$emailContext = null;
if ($review !== null) {
    try {
        $emailContext = fc_auth_invitation_email_context($pdo);
    } catch (Throwable) {
        $emailContext = null;
    }
}
$recipientEmail = $emailContext !== null
    ? (string) $emailContext['email']
    : ($review !== null ? (string) ($review['invited_email'] ?? '') : '');
$profileRequired = $review !== null && fc_invitation_profile_required();
$signedInUser = null;
$signedInEmail = null;

if ($review !== null && fc_is_logged_in()) {
    $currentUser = fc_current_user();
    if ($currentUser !== null) {
        $signedInUser = fc_user_find_by_id($pdo, (int) $currentUser['user_id'], true);
        $signedInEmail = fc_current_account_email($pdo);
    }
}

header('Referrer-Policy: same-origin');
$title = 'Challenge Invitation';
$contentView = 'views/public/crew_invitation.php';
require fc_path('views/layouts/public.php');
