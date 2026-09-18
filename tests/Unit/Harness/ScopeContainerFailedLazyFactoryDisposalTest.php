<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ScopeContainer;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\Harness\LazyDisposableFactoryProbe;

use function Greenlight\expect;

final class ScopeContainerFailedLazyFactoryDisposalTest
{
    #[Test]
    public function disposalDoesNotRetryAFailedLazyFactory(): void
    {
        LazyDisposableFactoryProbe::reset();
        $factoryCalls = 0;
        $failure = new \RuntimeException('factory broke');
        $container = new ScopeContainer();
        $service = $container->get(new ServiceDefinition(
            LazyDisposableFactoryProbe::class,
            Scope::PerTest,
            static function () use (&$factoryCalls, $failure): LazyDisposableFactoryProbe {
                ++$factoryCalls;

                throw $failure;
            },
        ));

        expect($service)
            ->because('ScopeContainer::get() MUST return LazyDisposableFactoryProbe.')
            ->toBeInstanceOf(LazyDisposableFactoryProbe::class);

        expect()->calling(static fn(): string => $service->value())
            ->because('the lazy factory failure MUST propagate from service use')
            ->toThrow($failure);

        $failures = $container->dispose();

        expect($factoryCalls)
            ->because('scope disposal MUST NOT retry a failed lazy factory')
            ->toBe(1);
        expect($failures)
            ->because('an uninitialized service has nothing to dispose')
            ->toBe([]);
        expect(LazyDisposableFactoryProbe::disposals())
            ->toBe(0);
    }
}
