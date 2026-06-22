<?php

declare(strict_types=1);

function fc_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone((string) fc_env('APP_TIMEZONE', 'America/New_York')));
}
