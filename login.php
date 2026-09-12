<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

if (fc_is_logged_in()) {
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

if (fc_google_auth_enabled()) {
    try {
        $prepared = fc_google_prepare_login_transaction(fc_db());
        $googleAuthConfig = [
            'enabled' => true,
            'client_id' => $prepared['client_id'],
            'transaction_id' => $prepared['transaction_id'],
            'state' => $prepared['state'],
            'nonce' => $prepared['nonce'],
            'csrf_token' => fc_csrf_token(),
            'endpoint' => '/auth/google/credential.php',
        ];
    } catch (Throwable $e) {
        fc_log('warning', 'Unable to prepare Google authentication transaction.', [
            'reason' => 'google_auth_prepare_failed',
        ]);
        $googleAuthConfig['reason'] = 'Google authentication is temporarily unavailable.';
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
