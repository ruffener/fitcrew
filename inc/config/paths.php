<?php

declare(strict_types=1);

function fc_path(string $relative = ''): string
{
    $base = dirname(__DIR__, 2);
    $relative = ltrim($relative, DIRECTORY_SEPARATOR . '/');

    return $relative === '' ? $base : $base . DIRECTORY_SEPARATOR . $relative;
}

/**
 * Return a path beneath the application/web root.
 *
 * FitCrew intentionally uses the repository root as the Apache document root.
 * Sensitive application directories are denied by the root .htaccess contract.
 */
function fc_web_path(string $relative = ''): string
{
    return fc_path($relative);
}

/**
 * Backward-compatible alias retained for early skeleton callers.
 * New code should prefer fc_web_path().
 */
function fc_public_path(string $relative = ''): string
{
    return fc_web_path($relative);
}

function fc_storage_path(string $relative = ''): string
{
    return fc_path('storage' . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR . '/') : ''));
}
