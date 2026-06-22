<?php

declare(strict_types=1);

function fc_response_code(int $code): void
{
    http_response_code($code);
}
