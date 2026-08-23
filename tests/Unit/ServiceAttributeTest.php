<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Service;

final readonly class ServiceAttributeTest
{
    #[Test]
    public function rejectsAnEmptyServiceIdentifier(): void
    {
        Expect::that(static fn(): Service => new Service(''))
            ->because('a service attribute MUST identify a container service')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Service identifier must not be empty.',
            );
    }

    #[Test]
    public function preservesAZeroStringServiceIdentifier(): void
    {
        Expect::that((new Service('0'))->id)
            ->because('a zero-string service identifier is not empty')
            ->toBe('0');
    }
}
