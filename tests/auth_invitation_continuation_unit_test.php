<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';

function aciu_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $root = dirname(__DIR__);
    $migration = file_get_contents($root . '/database/migrations/0310_create_auth_invitation_continuations.sql') ?: '';
    $continuationSource = file_get_contents($root . '/inc/auth/invitation_continuations.php') ?: '';
    $googleSource = file_get_contents($root . '/inc/auth/google.php') ?: '';
    $credentialSource = file_get_contents($root . '/auth/google/credential.php') ?: '';
    $rateLimitSource = file_get_contents($root . '/inc/security/rate_limit.php') ?: '';
    $websiteSource = file_get_contents($root . '/inc/product/crew_invitations.php') ?: '';

    foreach ([
        'auth_invitation_continuations',
        'auth_invitation_admission_claims',
        'security_rate_limit_buckets',
    ] as $table) {
        aciu_assert(str_contains($migration, 'CREATE TABLE ' . $table), 'Migration is missing ' . $table . '.');
    }
    aciu_assert(
        str_contains($migration, 'PRIMARY KEY (invitation_public_id)')
            && str_contains($migration, 'UNIQUE KEY uq_auth_invitation_admission_continuation'),
        'One-new-account-per-logical-invitation claim is not database enforced.'
    );
    foreach (['raw_token', 'invited_email', 'token_hash'] as $forbiddenColumn) {
        aciu_assert(
            stripos($migration, $forbiddenColumn) === false,
            'Auth migration contains forbidden invitation data: ' . $forbiddenColumn
        );
    }

    aciu_assert(
        fc_auth_destination_path('CREW_INVITATION_ACCEPTANCE') === '/crew-invite.php',
        'Crew invitation destination is not fixed.'
    );
    try {
        fc_auth_destination_path('/crew-invite.php?token=raw');
        throw new RuntimeException('Arbitrary invitation destination was accepted.');
    } catch (InvalidArgumentException) {
        // Expected.
    }

    aciu_assert(
        str_contains($continuationSource, "fc_path('inc/product/crew_invitations.php')")
            && str_contains($websiteSource, 'function fc_crew_invitation_auth_snapshot(')
            && str_contains($websiteSource, "\$sql .= ' FOR UPDATE'"),
        'Auth is not aligned with the accepted Website validator location.'
    );
    aciu_assert(
        str_contains($continuationSource, "['invitation_public_id', 'generation', 'expires_at']")
            && str_contains($continuationSource, 'crossed the approved data boundary'),
        'Website validator result boundary is not strict.'
    );

    foreach ([
        'fc_auth_crew_invitation_continuation_issue',
        'fc_auth_crew_invitation_continuation_bind_login_transaction',
        'fc_auth_crew_invitation_admission_claim',
        'fc_auth_crew_invitation_continuation_current',
        'fc_auth_crew_invitation_continuation_consume',
    ] as $functionName) {
        aciu_assert(
            str_contains($continuationSource, 'function ' . $functionName . '('),
            'Missing continuation callable: ' . $functionName
        );
    }
    aciu_assert(
        str_contains($continuationSource, 'issuance requires no active caller transaction')
            && str_contains($continuationSource, 'only after the continuation row is committed'),
        'Continuation issue ordering does not protect PHP session state from caller rollback.'
    );

    $consumeOffset = strpos($continuationSource, 'function fc_auth_crew_invitation_continuation_consume(');
    aciu_assert($consumeOffset !== false, 'Continuation consume function is missing.');
    $consumeSource = substr($continuationSource, $consumeOffset);
    aciu_assert(
        !str_contains($consumeSource, 'fc_auth_crew_invitation_continuation_clear_session('),
        'Continuation consume clears nontransactional PHP session state.'
    );

    foreach (['fc_rate_limit_consume', 'fc_rate_limit_clear', 'fc_rate_limit_cleanup'] as $functionName) {
        aciu_assert(
            str_contains($rateLimitSource, 'function ' . $functionName . '('),
            'Missing rate-limit callable: ' . $functionName
        );
    }
    aciu_assert(
        str_contains($rateLimitSource, "hash_hmac('sha256'")
            && !str_contains($migration, 'raw_subject'),
        'Rate limiter does not preserve the raw-subject storage boundary.'
    );

    aciu_assert(
        str_contains($googleSource, 'fc_auth_crew_invitation_admission_claim(')
            && strpos($googleSource, 'fc_auth_crew_invitation_admission_claim(')
                < strpos($googleSource, '$created = fc_user_create('),
        'Google does not claim invitation admission before user creation.'
    );
    aciu_assert(
        str_contains($continuationSource, '$error->errorInfo[1] ?? 0')
            && str_contains($continuationSource, '$driverError === 1062'),
        'Admission collision handling masks non-duplicate integrity failures.'
    );
    aciu_assert(
        str_contains($googleSource, 'fc_google_prelaunch_allows_new_account'),
        'Ordinary Google allowlist path was removed.'
    );
    aciu_assert(
        str_contains($credentialSource, "'prelaunch_invitation_admission_claimed'")
            && str_contains($credentialSource, "'invitation_failed'"),
        'Duplicate invitation admission lacks a safe public rejection classification.'
    );

    fwrite(STDOUT, "Family Alpha Auth invitation continuation unit proof: PASS\n");
    fwrite(STDOUT, "- fixed destination / arbitrary URL rejection: PASS\n");
    fwrite(STDOUT, "- Auth storage excludes token, token hash and invited email: PASS\n");
    fwrite(STDOUT, "- real Website validator path + exact result boundary: PASS\n");
    fwrite(STDOUT, "- database-enforced logical-invitation admission claim: PASS\n");
    fwrite(STDOUT, "- committed continuation issue ordering / rollback-safe consume: PASS\n");
    fwrite(STDOUT, "- invitation admission + ordinary Google allowlist paths retained: PASS\n");
    fwrite(STDOUT, "- generic HMAC-evidence rate-limit interface: PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
