<?php

declare(strict_types=1);

function fc_path(string $relative = ''): string
{
    $base = dirname(__DIR__, 2);
    $relative = ltrim($relative, DIRECTORY_SEPARATOR . '/');

    return $relative === '' ? $base : $base . DIRECTORY_SEPARATOR . $relative;
}

function fc_public_path(string $relative = ''): string
{
    return fc_path('public' . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR . '/') : ''));
}

function fc_storage_path(string $relative = ''): string
{
    return fc_path('storage' . ($relative !== '' ? DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR . '/') : ''));
}
