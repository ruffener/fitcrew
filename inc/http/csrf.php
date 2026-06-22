<?php

declare(strict_types=1);

function fc_csrf_token(): string
{
    if (empty($_SESSION['fitcrew_csrf_token'])) {
        $_SESSION['fitcrew_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['fitcrew_csrf_token'];
}

function fc_csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . fc_e(fc_csrf_token()) . '">';
}

function fc_validate_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['fitcrew_csrf_token'])
        && hash_equals($_SESSION['fitcrew_csrf_token'], $token);
}
