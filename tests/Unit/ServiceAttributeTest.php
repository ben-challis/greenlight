<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit;

use Greenlight\Attribute\Test;
use Greenlight\Harness\Service;

use function Greenlight\expect;

final readonly class ServiceAttributeTest
{
    #[Test]
    public function rejectsAnEmptyServiceIdentifier(): void
    {
        expect()->calling(static fn(): Service => new Service(''))
            ->because('a service attribute MUST identify a container service')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Service identifier must not be empty.',
            );
    }

    #[Test]
    public function preservesAZeroStringServiceIdentifier(): void
    {
        expect((new Service('0'))->id)
            ->because('a zero-string service identifier is not empty')
            ->toBe('0');
    }

    #[Test]
    public function aSourceCanUseTheParameterTypeAsItsIdentifier(): void
    {
        $service = new Service(source: 'billing');

        expect($service->id)->toBeNull();
        expect($service->source)->toBe('billing');
    }

    #[Test]
    public function rejectsAnEmptySourceName(): void
    {
        expect()->calling(static fn(): Service => new Service(source: ''))
            ->toThrow(\InvalidArgumentException::class, message: 'Service source must not be empty.');
    }

    #[Test]
    public function preservesAZeroStringSourceName(): void
    {
        expect((new Service(source: '0'))->source)->toBe('0');
    }
}
