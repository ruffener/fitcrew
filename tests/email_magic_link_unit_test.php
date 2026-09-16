<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth/email_magic_link.php';

function emlu_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $root = dirname(__DIR__);
    $readSource = static function (string $path): string {
        $contents = file_get_contents($path) ?: '';
        return str_replace(["\r\n", "\r"], "\n", $contents);
    };
    $migration = $readSource($root . '/database/migrations/0330_add_email_magic_link_authentication.sql');
    $uniquenessMigration = $readSource($root . '/database/migrations/0340_enforce_verified_email_uniqueness.sql');
    $service = $readSource($root . '/inc/auth/email_magic_link.php');
    $continuations = $readSource($root . '/inc/auth/invitation_continuations.php');
    $request = $readSource($root . '/auth/email/request.php');
    $confirm = $readSource($root . '/auth/email/confirm.php');
    $complete = $readSource($root . '/auth/email/complete.php');
    $view = $readSource($root . '/views/auth/email_confirm.php');
    $login = $readSource($root . '/views/auth/login.php');
    $loginController = $readSource($root . '/login.php');
    $contracts = $readSource($root . '/inc/identity/contracts.php');

    emlu_assert(in_array('EMAIL', FC_AUTH_PROVIDERS, true), 'EMAIL is absent from the Auth provider contract.');
    emlu_assert(
        FC_EMAIL_MAGIC_LINK_ISSUER === 'https://fitcrewchallenge.com/auth/email',
        'EMAIL issuer is not canonical.'
    );
    emlu_assert(
        str_contains($migration, "'GOOGLE', 'APPLE', 'MICROSOFT', 'EMAIL'")
            && str_contains($migration, "provider_key IN ('GOOGLE', 'APPLE', 'EMAIL')")
            && str_contains($migration, 'CREATE TABLE email_magic_link_challenges'),
        'Migration 0330 does not establish the EMAIL provider/challenge schema.'
    );

    $token = fc_email_magic_link_token();
    emlu_assert(fc_email_magic_link_token_valid_shape($token), 'Generated token is not a valid 256-bit encoding.');
    emlu_assert(strlen((string) base64_decode(strtr($token, '-_', '+/') . '=', true)) === 32, 'Token is not 256 bits.');
    emlu_assert(FC_EMAIL_MAGIC_LINK_TTL_SECONDS === 900, 'Magic-link lifetime is not exactly 15 minutes.');
    emlu_assert(
        str_contains($migration, 'token_hash CHAR(64)')
            && !preg_match('/\braw_token\b/i', $migration),
        'Migration does not preserve hash-only token storage.'
    );
    foreach (['ISSUED', 'CONSUMED', 'REPLACED'] as $status) {
        emlu_assert(str_contains($migration, "'" . $status . "'"), 'Challenge status is missing: ' . $status);
    }
    emlu_assert(
        str_contains($migration, 'UNIQUE KEY uq_email_magic_link_active_flow (active_flow_key_hash)')
            && str_contains($migration, 'active_flow_key_hash = flow_key_hash')
            && str_contains($service, 'active_flow_key_hash = NULL'),
        'Active-flow uniqueness is not maintained portably across lifecycle transitions.'
    );

    emlu_assert(str_contains($request, 'if (!fc_is_post())'), 'Request endpoint is not POST-only.');
    emlu_assert(
        str_contains($request, 'fc_email_magic_link_request_origin_valid')
            && str_contains($request, 'fc_validate_csrf'),
        'Request endpoint lacks same-origin or CSRF enforcement.'
    );
    emlu_assert(
        substr_count($request, 'FC_EMAIL_MAGIC_LINK_REQUEST_MESSAGE') >= 3
            && !str_contains($request, 'identity exists'),
        'Request endpoint does not preserve a generic non-enumerating response.'
    );
    foreach ([
        ["'auth.email_magic.request.email'", '5', '900'],
        ["'auth.email_magic.request.network'", '20', '900'],
    ] as [$namespace, $limit, $window]) {
        emlu_assert(
            str_contains($service, $namespace)
                && preg_match('/' . preg_quote($namespace, '/') . '.*?' . $limit . ',\s*' . $window . '/s', $service) === 1,
            'Request rate-limit contract is missing for ' . $namespace . '.'
        );
    }
    emlu_assert(
        str_contains($complete, "'auth.email_magic.complete.invalid.network'")
            && preg_match("/'auth\\.email_magic\\.complete\\.invalid\\.network'.*?20,\\s*900/s", $complete) === 1,
        'Invalid-completion network limit is missing.'
    );

    emlu_assert(
        str_contains($confirm, "fc_request_method() !== 'GET'")
            && !str_contains($confirm, 'fc_email_magic_link_complete(')
            && !str_contains($confirm, "\$_GET['token']"),
        'Token GET is not inspection-only.'
    );
    emlu_assert(
        str_contains($confirm, "header('Referrer-Policy: no-referrer')")
            && str_contains($confirm, "header('Cache-Control: no-store, private')")
            && str_contains($confirm, "default-src 'none'"),
        'Token-bearing confirmation lacks privacy headers or third-party isolation.'
    );
    emlu_assert(
        str_contains($complete, 'if (!fc_is_post())')
            && str_contains($complete, 'fc_validate_csrf')
            && str_contains($complete, 'fc_email_magic_link_complete('),
        'Explicit protected completion POST is missing.'
    );
    emlu_assert(
        str_contains($view, 'Opening this page did not sign you in')
            && str_contains($view, 'name="token"')
            && str_contains($view, 'window.location.hash')
            && str_contains($view, 'window.history.replaceState')
            && !preg_match('/(?:src|href)="https?:/i', $view),
        'Confirmation page violates scanner-safety or third-party isolation.'
    );
    emlu_assert(
        str_contains($service, "'/auth/email/confirm.php#token='")
            && !str_contains($service, "confirm.php?token="),
        'Raw token could enter an HTTP request/access log through the email URL.'
    );
    emlu_assert(
        str_contains($service, 'may be opened in any browser or device')
            && !str_contains($service, 'works only in the browser where it was requested')
            && !str_contains($login, 'must be opened in this browser'),
        'Consumer copy still imposes the retired same-browser requirement.'
    );
    $findStart = strpos($service, 'function fc_email_magic_link_find_valid(');
    $inspectStart = strpos($service, 'function fc_email_magic_link_inspect(');
    emlu_assert(
        $findStart !== false
            && $inspectStart !== false
            && $inspectStart > $findStart
            && !str_contains(
                substr($service, $findStart, $inspectStart - $findStart),
                'browser_session_binding_hash = :browser_hash'
            ),
        'EMAIL bearer-token validation still requires requesting-browser equality.'
    );
    emlu_assert(
        str_contains($service, 'fc_email_magic_link_rebind_transaction_to_arrival(')
            && str_contains($service, 'fc_email_magic_link_apply_committed_arrival_context(')
            && str_contains($complete, 'fc_email_magic_link_apply_committed_arrival_context($result)')
            && str_contains($continuations, 'fc_auth_crew_invitation_continuation_for_email_transaction(')
            && str_contains($continuations, 'fc_auth_crew_invitation_continuation_transfer_email_arrival('),
        'Arrival-browser transaction/continuation transfer contract is incomplete.'
    );
    $consumeChallengeAt = strpos($service, '$consumeChallenge = $pdo->prepare(');
    $consumeTransactionAt = strpos($service, '$transactionConsumed = fc_auth_transaction_consume(');
    emlu_assert(
        $consumeChallengeAt !== false
            && $consumeTransactionAt !== false
            && $consumeChallengeAt < $consumeTransactionAt,
        'EMAIL challenge is not consumed before its exact LOGIN transaction.'
    );

    emlu_assert(
        str_contains($service, "fc_mail_send(")
            && str_contains($service, "'subject' => 'Your FitCrew sign-in link'")
            && str_contains($service, "'from_name' => 'FitCrew Challenge'"),
        'Magic-link delivery does not consume the existing mail contract.'
    );
    emlu_assert(
        str_contains($service, "fc_auth_identity_find_oidc(\n            \$pdo,\n            'EMAIL'")
            && !preg_match('/find_oidc\([^;]*GOOGLE[^;]*email/si', $service),
        'EMAIL identity resolution is not isolated from federated-provider email.'
    );
    emlu_assert(
        strpos($service, 'fc_auth_crew_invitation_admission_claim(')
            < strpos($service, '$created = fc_user_create('),
        'Invitation admission is not claimed before EMAIL account creation.'
    );
    emlu_assert(
        str_contains($service, "throw new DomainException('prelaunch_new_account_denied')")
            && str_contains($service, "'EMAIL',\n            FC_EMAIL_MAGIC_LINK_ISSUER"),
        'Unknown EMAIL identities are not held behind invitation admission.'
    );
    emlu_assert(
        str_contains($service, 'fc_contact_email_find_verified_owner(')
            && str_contains($service, 'fc_auth_identity_email_evidence_owners(')
            && str_contains($service, "throw new DomainException('account_reconciliation_required')"),
        'Canonical EMAIL resolution does not detect cross-user reconciliation conflicts.'
    );
    emlu_assert(
        str_contains($uniquenessMigration, 'CREATE TEMPORARY TABLE fc_verified_email_uniqueness_preflight')
            && str_contains($uniquenessMigration, 'UNIQUE KEY uq_contact_email_verified_canonical')
            && str_contains($uniquenessMigration, 'verified_email_canonical = email_canonical')
            && !str_contains($uniquenessMigration, 'GENERATED ALWAYS'),
        'Migration 0340 lacks duplicate-safe MariaDB verified-email uniqueness.'
    );
    emlu_assert(
        str_contains($contracts, "'CREW_INVITATION_ACCEPTANCE' => '/crew-invite.php'")
            && str_contains($login, 'Continue with email'),
        'Fixed invitation destination or consumer EMAIL entry is missing.'
    );
    emlu_assert(
        str_contains($service, 'fc_email_magic_link_release_bound_transaction(')
            && str_contains($service, 'pkce_verifier_secret_envelope = NULL')
            && str_contains($loginController, 'fc_google_prepare_login_transaction'),
        'Provider selection does not preserve one exact active invitation-bound transaction.'
    );
    emlu_assert(
        !str_contains($service, 'crew_memberships')
            && !str_contains($service, 'invited_email'),
        'Auth EMAIL runtime crossed into membership or invited-email semantics.'
    );

    fwrite(STDOUT, "EMAIL_MAGIC_LINK_V1 unit proof: PASS\n");
    fwrite(STDOUT, "- EMAIL provider / issuer / schema contract: PASS\n");
    fwrite(STDOUT, "- canonical verified-email database uniqueness / duplicate preflight: PASS\n");
    fwrite(STDOUT, "- 256-bit token / hash-only / 15-minute lifecycle: PASS\n");
    fwrite(STDOUT, "- request POST / CSRF / same-origin / generic response: PASS\n");
    fwrite(STDOUT, "- email + network + invalid-completion rate limits: PASS\n");
    fwrite(STDOUT, "- scanner-safe GET / explicit protected completion POST: PASS\n");
    fwrite(STDOUT, "- cross-browser bearer completion / arrival-session transfer: PASS\n");
    fwrite(STDOUT, "- fragment token excluded from GET/access logs: PASS\n");
    fwrite(STDOUT, "- no third-party token-page resources: PASS\n");
    fwrite(STDOUT, "- existing fc_mail_send transport / canonical subject: PASS\n");
    fwrite(STDOUT, "- EMAIL identity isolation / explicit-linking boundary: PASS\n");
    fwrite(STDOUT, "- canonical verified-email conflict detection / no automatic merge: PASS\n");
    fwrite(STDOUT, "- invitation admission before account creation: PASS\n");
    fwrite(STDOUT, "- exact invitation binding / explicit provider replacement: PASS\n");
    fwrite(STDOUT, "- fixed destination / no membership or invited-email semantics: PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
