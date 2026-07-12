<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Symfony;

/**
 * Service registered only under the string id fixture.named_greeter, so
 * type-based lookup misses it and only #[Service] reaches it.
 */
final class NamedGreeter
{
    public function greet(): string
    {
        return 'Hello from fixture.named_greeter!';
    }
}
