<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Harness;

use Greenlight\Doubles\Fake;

final readonly class LazyFactoryProbe implements Fake
{
    public function __construct(private string $value) {}

    public function value(): string
    {
        return $this->value;
    }
}
