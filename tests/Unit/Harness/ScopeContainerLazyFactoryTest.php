<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\PhpVersionAtLeast;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ScopeContainer;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\Harness\LazyFactoryProbe;

use function Greenlight\expect;

final class ScopeContainerLazyFactoryTest
{
    #[Test]
    #[SkipUnless(PhpVersionAtLeast::class, '8.4')]
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

        expect($service)
            ->because('ScopeContainer::get() MUST return LazyFactoryProbe.')
            ->toBeInstanceOf(LazyFactoryProbe::class);

        expect($factoryCalls)
            ->because('ScopeContainer::get() MUST NOT invoke a lazy factory')
            ->toBe(0);

        expect()->calling(static fn() => $service->value())
            ->because('the lazy factory failure MUST propagate from the first service use')
            ->toThrow($failure);
        expect($factoryCalls)
            ->toBe(1);
    }
}
