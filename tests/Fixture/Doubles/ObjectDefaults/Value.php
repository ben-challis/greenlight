<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

final readonly class Value
{
    /** @param array<array-key, mixed> $payload */
    public function __construct(public string $label, public array $payload = []) {}
}
