<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;

final readonly class ServiceDefinitionValidationTest
{
    #[Test]
    public function rejectsAnEmptyServiceType(): void
    {
        Expect::that(static fn(): ServiceDefinition => new ServiceDefinition(
            '',
            Scope::PerTest,
            static fn(): \stdClass => new \stdClass(),
        ))
            ->because('a harness service definition MUST identify its injected type')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Harness service type cannot be empty.',
            );
    }

    #[Test]
    public function rejectsAnEmptySourceName(): void
    {
        Expect::that(static fn(): ServiceDefinition => new ServiceDefinition(
            \stdClass::class,
            Scope::PerTest,
            static fn(): \stdClass => new \stdClass(),
            source: '',
        ))->toThrow(\InvalidArgumentException::class, message: 'Service source must not be empty.');
    }
}
