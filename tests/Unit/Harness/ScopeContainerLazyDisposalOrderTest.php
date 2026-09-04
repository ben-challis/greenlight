<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ScopeContainer;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\Harness\FailingDisposable;
use Greenlight\Tests\Fixture\Lifecycle\Services\SecondaryServiceProbe;
use Greenlight\Tests\Fixture\Lifecycle\Services\ServiceProbe;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final class ScopeContainerLazyDisposalOrderTest
{
    #[Test]
    public function statelessServicesUseTheirFactoryAndRemainDisposable(): void
    {
        $calls = 0;
        $container = new ScopeContainer();
        $container->get(new ServiceDefinition(
            FailingDisposable::class,
            Scope::PerTest,
            static function () use (&$calls): FailingDisposable {
                ++$calls;

                return new FailingDisposable();
            },
        ));

        Expect::that($calls)->toBe(1);
        Expect::that($container->dispose())->toHaveCount(1);
        Expect::that($container->dispose())->toBe([]);
    }

    #[Test]
    public function lazyServicesDisposeInReverseInitializationOrder(): void
    {
        ServiceProbe::reset();
        TraceLog::drain();
        $container = new ScopeContainer();
        $first = $container->get(new ServiceDefinition(
            ServiceProbe::class,
            Scope::PerTest,
            static fn(): ServiceProbe => new ServiceProbe(),
        ));
        $second = $container->get(new ServiceDefinition(
            SecondaryServiceProbe::class,
            Scope::PerTest,
            static fn(): SecondaryServiceProbe => new SecondaryServiceProbe(),
        ));

        Expect::that($first)->toBeInstanceOf(ServiceProbe::class);
        Expect::that($second)->toBeInstanceOf(SecondaryServiceProbe::class);
        $second->touch();
        $first->touch();

        Expect::that($container->dispose())->toBe([]);
        Expect::that(TraceLog::drain())->toBe([
            'secondary:created',
            'secondary:touched',
            'probe1:created',
            'probe1:touched',
            'probe1:disposed',
            'secondary:disposed',
        ]);
        Expect::that($container->dispose())->toBe([]);
        Expect::that(TraceLog::drain())->toBe([]);
    }
}
