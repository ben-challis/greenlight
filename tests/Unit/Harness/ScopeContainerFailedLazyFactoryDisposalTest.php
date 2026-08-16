<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
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
        $container = new ScopeContainer();
        $service = $container->get(new ServiceDefinition(
            LazyDisposableFactoryProbe::class,
            Scope::PerTest,
            static function () use (&$factoryCalls): LazyDisposableFactoryProbe {
                ++$factoryCalls;

                throw new \RuntimeException('factory broke');
            },
        ));

        if (!$service instanceof LazyDisposableFactoryProbe) {
            Fail::because(\sprintf(
                'Expected ScopeContainer::get() to return LazyDisposableFactoryProbe, got %s.',
                \get_debug_type($service),
            ));
        }

        Expect::that(static fn(): string => $service->value())
            ->because('the lazy factory failure MUST propagate from service use')
            ->toThrow(\RuntimeException::class, message: 'factory broke');

        $failures = $container->dispose();

        Expect::that($factoryCalls)
            ->because('scope disposal MUST NOT retry a failed lazy factory')
            ->toBe(1);
        Expect::that($failures)
            ->because('an uninitialized service has nothing to dispose')
            ->toBe([]);
        Expect::that(LazyDisposableFactoryProbe::disposals())
            ->toBe(0);
    }
}
