<?php

declare(strict_types=1);

function fc_request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function fc_is_post(): bool
{
    return fc_request_method() === 'POST';
}
