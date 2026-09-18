<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Injection;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final readonly class InjectionTest
{
    public function __construct(private InjectedProbe $probe) {}

    #[Test]
    public function usesTheInjectedService(): void
    {
        Expect::value($this->probe->ping())->toBe('pong');
    }
}
