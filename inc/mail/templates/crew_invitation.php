<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function fc_mail_crew_invitation_message(
    string $recipientEmail,
    string $inviterDisplayName,
    string $crewName,
    string $challengeName,
    string $accountPresence,
    string $acceptUrl
): array {
    $inviterDisplayName = trim($inviterDisplayName);
    $crewName = trim($crewName);
    $challengeName = trim($challengeName);
    $parts = preg_split('/\s+/', $inviterDisplayName !== '' ? $inviterDisplayName : 'FitCrew');
    $firstName = is_array($parts) && isset($parts[0]) && $parts[0] !== '' ? $parts[0] : 'FitCrew';
    $fromName = $firstName . ' via FitCrew Challenge';
    $subject = $firstName . ' invited you to ' . ($challengeName !== '' ? $challengeName : $crewName);

    $accountLine = $accountPresence === 'KNOWN_ACCOUNT'
        ? 'You already have a FitCrew account. This invitation can sign you in with this email after you accept the Challenge.'
        : 'If you’re new to FitCrew, this invitation verifies your email and FitCrew will ask only for the account details still required after you accept the Challenge.';

    $text = $firstName . ' invited you to ' . $challengeName . ' with ' . $crewName . " on FitCrew Challenge.\n\n" .
        "Review the Challenge before you decide:\n" . $acceptUrl . "\n\n" .
        $accountLine . "\n\n" .
        "Opening the invitation does not create Crew membership, Challenge participation, or health authorization.\n" .
        'FitCrew Challenge';

    $safeCrew = htmlspecialchars($crewName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeChallenge = htmlspecialchars($challengeName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeFirst = htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($acceptUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeAccountLine = htmlspecialchars($accountLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#13233a;line-height:1.5">' .
        '<div style="max-width:620px;margin:0 auto;padding:24px">' .
        '<h1 style="font-size:24px;margin:0 0 16px">You’re invited to ' . $safeChallenge . '</h1>' .
        '<p><strong>' . $safeFirst . '</strong> invited you to a Challenge with <strong>' . $safeCrew . '</strong>.</p>' .
        '<p>' . $safeAccountLine . '</p>' .
        '<p style="margin:28px 0"><a href="' . $safeUrl . '" style="background:#ef6c2f;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;display:inline-block;font-weight:700">Review Challenge</a></p>' .
        '<p style="font-size:14px;color:#5e6878">Opening the invitation does not create Crew membership, Challenge participation, or health authorization.</p>' .
        '<p style="font-size:14px;color:#5e6878">If the button does not work, open:<br>' . $safeUrl . '</p>' .
        '</div></body></html>';

    return [
        'to' => $recipientEmail,
        'from_name' => $fromName,
        'subject' => $subject,
        'text_body' => $text,
        'html_body' => $html,
        'tag' => 'challenge-invitation',
    ];
}
