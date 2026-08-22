<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

header('Cache-Control: no-store');

if (!fc_is_post()) {
    http_response_code(405);
    exit('Method not allowed.');
}

if (!fc_microsoft_auth_enabled()) {
    fc_flash('notice', 'Microsoft sign-in is not currently available.');
    fc_redirect('/login.php');
}

if (!fc_microsoft_request_origin_valid($_SERVER['HTTP_ORIGIN'] ?? null)) {
    try {
        fc_microsoft_audit_rejection(fc_db(), 'origin_failed');
    } catch (Throwable) {
    }
    fc_flash('error', 'Microsoft sign-in could not be started.');
    fc_redirect('/login.php');
}

if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
    try {
        fc_microsoft_audit_rejection(fc_db(), 'csrf_failed');
    } catch (Throwable) {
    }
    fc_flash('error', 'Microsoft sign-in could not be started.');
    fc_redirect('/login.php');
}

try {
    $prepared = fc_microsoft_prepare_login_transaction(fc_db());
    fc_redirect($prepared['authorization_url']);
} catch (Throwable) {
    fc_log('error', 'Microsoft authentication start failed unexpectedly.', [
        'reason' => 'microsoft_auth_start_failure',
    ]);
    try {
        fc_microsoft_audit_rejection(fc_db(), 'unexpected_failure');
    } catch (Throwable) {
    }
    fc_flash('error', 'Microsoft sign-in is temporarily unavailable. Please try again.');
    fc_redirect('/login.php');
}
