<?php

declare(strict_types=1);

function fc_trimmed_string(array $source, string $key): string
{
    return trim((string) ($source[$key] ?? ''));
}
