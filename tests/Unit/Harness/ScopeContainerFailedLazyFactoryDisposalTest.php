<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ScopeContainer;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\Harness\LazyDisposableFactoryProbe;

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

        Expect::value($service)
            ->because('ScopeContainer::get() MUST return LazyDisposableFactoryProbe.')
            ->toBeInstanceOf(LazyDisposableFactoryProbe::class);

        Expect::calling(static fn(): string => $service->value())
            ->because('the lazy factory failure MUST propagate from service use')
            ->toThrow($failure);

        $failures = $container->dispose();

        Expect::value($factoryCalls)
            ->because('scope disposal MUST NOT retry a failed lazy factory')
            ->toBe(1);
        Expect::value($failures)
            ->because('an uninitialized service has nothing to dispose')
            ->toBe([]);
        Expect::value(LazyDisposableFactoryProbe::disposals())
            ->toBe(0);
    }
}
