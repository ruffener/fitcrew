<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth/email_magic_link.php';

if (fc_is_logged_in()) {
    if (fc_auth_crew_invitation_continuation_session_public_id() !== null) {
        try {
            $continuation = fc_auth_crew_invitation_continuation_bind_existing_session(
                fc_db(),
                fc_current_user()
            );
            fc_redirect((string) $continuation['destination']);
        } catch (Throwable $error) {
            fc_auth_crew_invitation_continuation_clear_session();
            fc_log('warning', 'Unable to continue an authenticated Crew invitation.', [
                'reason' => 'crew_invitation_continuation_failed',
            ]);
            fc_flash('error', 'This Crew invitation changed or expired. Open the latest invitation email and try again.');
        }
    }
    fc_redirect('/app.php');
}

header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cross-Origin-Opener-Policy: same-origin-allow-popups');

$entryIntent = (string) ($_GET['intent'] ?? 'signin');
$entryIntent = $entryIntent === 'create' ? 'create' : 'signin';
$googleAuthConfig = [
    'enabled' => false,
    'reason' => 'Google authentication setup is not complete yet.',
];
$microsoftAuthConfig = [
    'visible' => fc_microsoft_auth_consumer_visible(),
    'enabled' => false,
    'reason' => 'Microsoft authentication setup is not complete yet.',
];
$emailAuthConfig = [
    'csrf_token' => fc_csrf_token(),
    'request_endpoint' => '/auth/email/request.php',
];
if (fc_google_auth_enabled()) {
    try {
        $prepared = fc_google_prepare_login_transaction(fc_db());
        $googleAuthConfig = [
            'enabled' => true,
            'client_id' => $prepared['client_id'],
            'transaction_id' => $prepared['transaction_id'],
            'state' => $prepared['state'],
            'nonce' => $prepared['nonce'],
            'expires_at' => $prepared['expires_at'],
            'csrf_token' => fc_csrf_token(),
            'endpoint' => '/auth/google/credential.php',
            'refresh_endpoint' => '/auth/google/refresh.php',
        ];
    } catch (Throwable $e) {
        if ($e instanceof DomainException && $e->getMessage() === 'auth_provider_choice_in_progress') {
            $googleAuthConfig['reason'] = 'A sign-in choice is already in progress in this browser. Complete that sign-in to continue.';
            $googleAuthConfig['status'] = 'Sign-in in progress';
        } else {
            fc_log('warning', 'Unable to prepare Google authentication transaction.', [
                'reason' => 'google_auth_prepare_failed',
            ]);
            $googleAuthConfig['reason'] = 'Google authentication is temporarily unavailable.';
            $googleAuthConfig['status'] = 'Try again';
        }
    }
}


if (fc_microsoft_auth_consumer_available()) {
    $microsoftAuthConfig = [
        'visible' => true,
        'enabled' => true,
        'csrf_token' => fc_csrf_token(),
        'start_endpoint' => '/auth/microsoft/start.php',
    ];
} elseif ((bool) $microsoftAuthConfig['visible']) {
    $configured = fc_microsoft_auth_config();
    if ((bool) ($configured['enabled'] ?? false)) {
        $microsoftAuthConfig['reason'] = 'Microsoft authentication configuration is incomplete.';
    }
}

$title = $entryIntent === 'create' ? 'Create Account' : 'Account Access';
$contentView = 'views/auth/login.php';
require fc_path('views/layouts/auth.php');
