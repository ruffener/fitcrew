<?php

declare(strict_types=1);

function fc_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (bool) fc_env('SESSION_SECURE', false);
    $httpOnly = (bool) fc_env('SESSION_HTTP_ONLY', true);
    $sameSite = (string) fc_env('SESSION_SAME_SITE', 'Lax');
    $name = (string) fc_env('SESSION_NAME', 'fitcrew_session');

    session_name($name);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => $httpOnly,
        'samesite' => $sameSite,
    ]);

    session_start();
}
