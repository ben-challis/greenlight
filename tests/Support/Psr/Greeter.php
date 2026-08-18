<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support\Psr;

/** @internal */
final readonly class Greeter
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name . '!';
    }
}
