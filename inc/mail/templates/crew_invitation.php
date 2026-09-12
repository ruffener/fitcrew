<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function fc_mail_crew_invitation_message(string $recipientEmail, string $inviterDisplayName, string $crewName, string $acceptUrl): array
{
    $inviterDisplayName = trim($inviterDisplayName);
    $crewName = trim($crewName);
    $parts = preg_split('/\s+/', $inviterDisplayName !== '' ? $inviterDisplayName : 'FitCrew');
    $firstName = is_array($parts) && isset($parts[0]) && $parts[0] !== '' ? $parts[0] : 'FitCrew';
    $fromName = $firstName . ' via FitCrew Challenge';
    $subject = $firstName . ' invited you to ' . $crewName;

    $text = $firstName . " invited you to join " . $crewName . " on FitCrew Challenge.\n\n" .
        "Review and accept your invitation:\n" . $acceptUrl . "\n\n" .
        "Joining a Crew is always your choice. Opening this email does not create membership.\n" .
        "FitCrew Challenge";

    $safeCrew = htmlspecialchars($crewName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeFirst = htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($acceptUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#13233a;line-height:1.5">' .
        '<div style="max-width:620px;margin:0 auto;padding:24px">' .
        '<h1 style="font-size:24px;margin:0 0 16px">You’re invited to ' . $safeCrew . '</h1>' .
        '<p><strong>' . $safeFirst . '</strong> invited you to join their Crew on FitCrew Challenge.</p>' .
        '<p style="margin:28px 0"><a href="' . $safeUrl . '" style="background:#ef6c2f;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;display:inline-block;font-weight:700">Review Invitation</a></p>' .
        '<p style="font-size:14px;color:#5e6878">Joining is always your choice. Opening this email does not create Crew membership.</p>' .
        '<p style="font-size:14px;color:#5e6878">If the button does not work, open:<br>' . $safeUrl . '</p>' .
        '</div></body></html>';

    return [
        'to' => $recipientEmail,
        'from_name' => $fromName,
        'subject' => $subject,
        'text_body' => $text,
        'html_body' => $html,
        'tag' => 'crew-invitation',
    ];
}
