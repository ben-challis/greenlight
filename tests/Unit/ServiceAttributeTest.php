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

    #[Test]
    public function aSourceCanUseTheParameterTypeAsItsIdentifier(): void
    {
        $service = new Service(source: 'billing');

        Expect::that($service->id)->toBeNull();
        Expect::that($service->source)->toBe('billing');
    }

    #[Test]
    public function rejectsAnEmptySourceName(): void
    {
        Expect::that(static fn(): Service => new Service(source: ''))
            ->toThrow(\InvalidArgumentException::class, message: 'Service source must not be empty.');
    }

    #[Test]
    public function preservesAZeroStringSourceName(): void
    {
        Expect::that((new Service(source: '0'))->source)->toBe('0');
    }
}
