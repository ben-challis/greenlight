<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Symfony;

/** Registered by id only, so type-based lookup cannot resolve it. */
final class NamedGreeter
{
    public function greet(): string
    {
        return 'Hello from fixture.named_greeter!';
    }
}
