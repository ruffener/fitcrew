<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }

function p3_assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$landing = file_get_contents($root . '/crew-invite.php') ?: '';
$service = file_get_contents($root . '/inc/product/crew_invitations.php') ?: '';
$crew = file_get_contents($root . '/crew.php') ?: '';
$view = file_get_contents($root . '/views/public/crew_invitation.php') ?: '';
$migration = file_get_contents($root . '/database/migrations/0320_crew_invitation_delivery_truth.sql') ?: '';

p3_assert(str_contains($landing, "header('Referrer-Policy: no-referrer')"), 'Raw-token landing must send no-referrer.');
p3_assert(str_contains($landing, 'fc_auth_crew_invitation_continuation_issue('), 'Raw-token landing must issue Auth continuation.');
p3_assert(str_contains($landing, "fc_redirect((string) \$authContinuation['next_path'])"), 'Raw-token landing must redirect immediately to Auth next path.');
p3_assert(str_contains($landing, 'fc_auth_crew_invitation_continuation_current($pdo)'), 'Clean invitation page must read current Auth continuation.');
p3_assert(str_contains($landing, 'fc_crew_invitation_accept_continuation('), 'Runtime acceptance must use continuation acceptance.');
p3_assert(!str_contains($view, 'name="token"'), 'Acceptance form must not carry the raw invitation token.');
p3_assert(!str_contains($view, 'invited_email'), 'Clean invitation view must not compare or expose invitation email as identity.');
p3_assert(str_contains($view, 'You’re signed in as'), 'Clean invitation page must show signed-in FitCrew account context.');
p3_assert(str_contains($view, 'Accept as'), 'Explicit acceptance must remain required.');

p3_assert(str_contains($service, 'fc_crew_invitation_auth_snapshot('), 'Final Website validator missing.');
p3_assert((bool) preg_match('/fc_crew_invitation_auth_snapshot\s*\([^;]+?true\s*\)/s', $service), 'Acceptance must request locking validation.');
p3_assert(str_contains($service, 'fc_auth_crew_invitation_continuation_consume('), 'Acceptance must consume Auth continuation.');
p3_assert(str_contains($landing, '$pdo->beginTransaction();'), 'Website controller must own one SQL acceptance transaction.');
p3_assert(str_contains($landing, '$pdo->rollBack();'), 'Website controller must roll back acceptance failure.');
p3_assert(str_contains($landing, '$pdo->commit();'), 'Website controller must commit acceptance atomically.');
p3_assert(str_contains($service, 'requires an active Website-owned transaction'), 'Acceptance helper must require the caller-owned transaction.');
p3_assert(str_contains($service, "role_code") && str_contains($service, "!== 'OWNER'"), 'Already-member acceptance must preserve Owner role.');

foreach ([
    "FC_CREW_INVITATION_RATE_ISSUE_NAMESPACE = 'crew_invitation.issue'",
    "FC_CREW_INVITATION_RATE_RESEND_NAMESPACE = 'crew_invitation.resend'",
    "FC_CREW_INVITATION_RATE_INVALID_RAW_NAMESPACE = 'crew_invitation.invalid_raw'",
] as $needle) {
    p3_assert(str_contains($service, $needle), 'Invitation rate-limit namespace missing: ' . $needle);
}
p3_assert(
    (bool) preg_match('/function\\s+fc_crew_invitation_rate_limit_issue\\b.*?fc_rate_limit_consume\\s*\\(.*?10\\s*,\\s*900\\s*\\)/s', $service),
    'Issue rate-limit policy mismatch.'
);
p3_assert(
    (bool) preg_match('/function\\s+fc_crew_invitation_rate_limit_resend\\b.*?fc_rate_limit_consume\\s*\\(.*?5\\s*,\\s*900\\s*\\)/s', $service),
    'Resend rate-limit policy mismatch.'
);
p3_assert(
    (bool) preg_match('/function\\s+fc_crew_invitation_rate_limit_invalid_raw\\b.*?fc_rate_limit_consume\\s*\\(.*?20\\s*,\\s*900\\s*\\)/s', $service),
    'Invalid-token rate-limit policy mismatch.'
);
p3_assert(
    !(bool) preg_match('/fc_rate_limit_consume\\s*\\(\\s*\\$pdo\\s*,\\s*FC_CREW_INVITATION_RATE_INVALID_RAW_NAMESPACE\\s*,\\s*\\$token\\b/s', $service),
    'Raw bearer token must never be a rate-limit subject.'
);
p3_assert(str_contains($crew, 'fc_crew_invitation_rate_limit_issue('), 'Issue limiter is not applied.');
p3_assert(str_contains($crew, 'fc_crew_invitation_rate_limit_resend('), 'Resend limiter is not applied.');
p3_assert(str_contains($landing, 'fc_crew_invitation_rate_limit_invalid_raw('), 'Invalid raw-token limiter is not applied.');

foreach (['PENDING_SEND','TRANSPORT_ACCEPTED','TRANSPORT_FAILED','transport_driver','transport_message_id','transport_attempted_at'] as $needle) {
    p3_assert(str_contains($migration, $needle), 'Delivery truth migration missing: ' . $needle);
}
p3_assert(str_contains($migration, 'MODIFY sent_at DATETIME(6) NULL DEFAULT NULL'), 'sent_at must become nullable transport-accepted time.');
p3_assert(str_contains($service, 'TRANSPORT_FAILED'), 'Failed transport state is not recorded.');
p3_assert(str_contains($service, 'TRANSPORT_ACCEPTED'), 'Accepted transport state is not recorded.');
p3_assert(str_contains($service, 'transport_message_id=:message_id'), 'Provider message ID is not recorded.');
p3_assert(str_contains($service, 'PENDING_SEND'), 'Resend/current-generation pending transport state missing.');

p3_assert(!str_contains($landing, 'email_at_provider') && !str_contains($service, 'email_at_provider'), 'Provider email comparison must remain absent.');

fwrite(STDOUT, "Crew invitation PASS 3 contract proof: PASS\n");
fwrite(STDOUT, "- Raw token one-time exchange / clean continuation flow: PASS\n");
fwrite(STDOUT, "- Explicit atomic acceptance / no provider-email identity: PASS\n");
fwrite(STDOUT, "- Delivery truth semantics: PASS\n");
fwrite(STDOUT, "- Invitation rate-limit policies/call sites: PASS\n");
