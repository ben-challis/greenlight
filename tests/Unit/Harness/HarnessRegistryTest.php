<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;

final class HarnessRegistryTest
{
    #[Test]
    public function duplicateHarnessServiceTypesAreRejected(): void
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
        $registry = new HarnessRegistry([$registered]);

        Expect::that(static function () use ($registry, $duplicate): void {
            $registry->register($duplicate);
        })
            ->because('a harness service type has only one definition')
            ->toThrow(
                \LogicException::class,
                message: 'A harness service for ArrayObject is already registered.',
            );
        Expect::that($registry->find(\ArrayObject::class))
            ->because('a rejected duplicate does not replace the registered definition')
            ->toBe($registered);
    }
}
