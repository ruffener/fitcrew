<?php

declare(strict_types=1);

function fc_config(): array
{
    return [
        'name' => (string) fc_env('APP_NAME', 'FitCrew Challenge'),
        'env' => (string) fc_env('APP_ENV', 'local'),
        'debug' => (bool) fc_env('APP_DEBUG', false),
        'url' => (string) fc_env('APP_URL', 'http://fitcrew.test'),
        'timezone' => (string) fc_env('APP_TIMEZONE', 'America/New_York'),
    ];
}
