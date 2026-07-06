<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Injection;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final readonly class InjectionTest
{
    public function __construct(
        private Expect $expect,
    ) {}

    #[Test]
    public function usesTheInjectedExpect(): void
    {
        $this->expect->that(1 + 1)->toBe(2);
    }
}
