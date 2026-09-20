<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Injection;

use Greenlight\Attribute\Test;


use function Greenlight\expect;

final readonly class InjectionTest
{
    public function __construct(private InjectedProbe $probe) {}

    #[Test]
    public function usesTheInjectedService(): void
    {
        expect($this->probe->ping())->toBe('pong');
    }
}
