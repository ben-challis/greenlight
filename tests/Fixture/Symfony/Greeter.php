<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Symfony;

/**
 * Plain private container service resolved by type in the bridge tests.
 */
final class Greeter
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name . '!';
    }
}
