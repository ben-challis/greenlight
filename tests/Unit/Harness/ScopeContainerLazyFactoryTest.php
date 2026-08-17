<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
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

        if (!$service instanceof LazyFactoryProbe) {
            Fail::because(\sprintf(
                'Expected ScopeContainer::get() to return LazyFactoryProbe, got %s.',
                \get_debug_type($service),
            ));
        }

        Expect::that($factoryCalls)
            ->because('ScopeContainer::get() MUST NOT invoke a lazy factory')
            ->toBe(0);

        Expect::that(static fn() => $service->value())
            ->because('the lazy factory failure MUST propagate from the first service use')
            ->toThrow(
                static function (\RuntimeException $caught) use ($failure): void {
                    Expect::that($caught)->toBe($failure);
                },
            );
        Expect::that($factoryCalls)
            ->toBe(1);
    }
}
