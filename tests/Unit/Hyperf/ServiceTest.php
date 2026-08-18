<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Hyperf;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Hyperf\Service;

final readonly class ServiceTest
{
    #[Test]
    public function storesANonEmptyServiceId(): void
    {
        Expect::that(new Service('probe.greeter')->id)->toBe('probe.greeter');
    }

    #[Test]
    public function rejectsAnEmptyServiceId(): void
    {
        Expect::that(static fn(): Service => new Service(''))
            ->toThrow(\InvalidArgumentException::class, message: 'Service ID MUST NOT be empty.');
    }
}
