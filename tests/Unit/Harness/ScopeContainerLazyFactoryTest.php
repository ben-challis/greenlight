<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ScopeContainer;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\Harness\LazyFactoryProbe;

final class ScopeContainerLazyFactoryTest
{
    #[Test]
    public function aLazyFactoryFailureIsDeferredAndPropagated(): void
    {
        $factoryCalls = 0;
        $failure = new \RuntimeException('factory broke');
        $container = new ScopeContainer();
        $service = $container->get(new ServiceDefinition(
            LazyFactoryProbe::class,
            Scope::PerTest,
            static function () use (&$factoryCalls, $failure): LazyFactoryProbe {
                ++$factoryCalls;

                throw $failure;
            },
        ));

        Expect::that($service)
            ->because('ScopeContainer::get() MUST return LazyFactoryProbe.')
            ->toBeInstanceOf(LazyFactoryProbe::class);

        Expect::that($factoryCalls)
            ->because('ScopeContainer::get() MUST NOT invoke a lazy factory')
            ->toBe(0);

        Expect::that(static fn() => $service->value())
            ->because('the lazy factory failure MUST propagate from the first service use')
            ->toThrow($failure);
        Expect::that($factoryCalls)
            ->toBe(1);
    }
}
