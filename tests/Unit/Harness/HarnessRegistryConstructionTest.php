<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;

final class HarnessRegistryConstructionTest
{
    #[Test]
    public function duplicateInitialServiceTypesAreRejected(): void
    {
        $registered = new ServiceDefinition(
            \ArrayObject::class,
            Scope::PerRun,
            static fn(): \ArrayObject => new \ArrayObject(),
        );
        $duplicate = new ServiceDefinition(
            \ArrayObject::class,
            Scope::PerTest,
            static fn(): \ArrayObject => new \ArrayObject(),
        );

        Expect::that(static fn(): HarnessRegistry => new HarnessRegistry([
            $registered,
            $duplicate,
        ]))
            ->because('initial definitions have one entry for each service type')
            ->toThrow(
                \LogicException::class,
                message: 'A harness service for ArrayObject is already registered.',
            );
    }
}
