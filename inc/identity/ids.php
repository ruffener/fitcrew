<?php

declare(strict_types=1);

use Symfony\Component\Uid\Ulid;

function fc_new_public_id(): string
{
    if (!class_exists(Ulid::class)) {
        throw new RuntimeException(
            'The symfony/uid dependency is required. Run Composer before using Phase 2A2 identity services.'
        );
    }

    return (new Ulid())->toBase32();
}

function fc_public_id_is_valid(string $value): bool
{
    return class_exists(Ulid::class) && Ulid::isValid($value);
}
