<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ScopeContainer;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\Lifecycle\DisposeFails\FailingDisposalProbe;
use Greenlight\Tests\Fixture\Lifecycle\Services\ServiceProbe;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final class ScopeContainerTest
{
    #[Test]
    public function reusesTheServiceWithinTheScope(): void
    {
        $container = new ScopeContainer();
        $definition = new ServiceDefinition(\ArrayObject::class, Scope::PerTest, static fn(): \ArrayObject => new \ArrayObject());

        $first = $container->get($definition);
        $second = $container->get($definition);

        Expect::that($second)->because('reuses the service within the scope')->toBe($first);
    }

    #[Test]
    public function anUntouchedLazyServiceIsNeverConstructedNorDisposed(): void
    {
        ServiceProbe::reset();
        TraceLog::drain();

        $container = new ScopeContainer();
        $definition = new ServiceDefinition(ServiceProbe::class, Scope::PerTest, static fn(): ServiceProbe => new ServiceProbe());

        $container->get($definition);
        $failures = $container->dispose();

        Expect::that($failures)->because('an untouched lazy service is never constructed nor disposed')->toBe([])->and(TraceLog::drain())->toBe([]);
    }

    #[Test]
    public function touchedServicesDisposeInReverseCreationOrder(): void
    {
        ServiceProbe::reset();
        TraceLog::drain();

        $container = new ScopeContainer();
        $probeDefinition = new ServiceDefinition(ServiceProbe::class, Scope::PerTest, static fn(): ServiceProbe => new ServiceProbe());
        $otherDefinition = new ServiceDefinition(\ArrayObject::class, Scope::PerTest, static fn(): \ArrayObject => new \ArrayObject());

        $probe = $container->get($probeDefinition);
        $container->get($otherDefinition);

        if (!$probe instanceof ServiceProbe) {
            Fail::because(\sprintf(
                'Expected ScopeContainer::get() to return ServiceProbe, got %s.',
                \get_debug_type($probe),
            ));
        }

        $probe->touch();
        $container->dispose();

        Expect::that(TraceLog::drain())->because('touched services dispose in reverse creation order')->toBe(['probe1:created', 'probe1:touched', 'probe1:disposed']);
    }

    #[Test]
    public function disposalFailuresAreCollectedNotThrown(): void
    {
        $container = new ScopeContainer();
        $definition = new ServiceDefinition(
            FailingDisposalProbe::class,
            Scope::PerTest,
            static fn(): FailingDisposalProbe => new FailingDisposalProbe(),
        );

        $probe = $container->get($definition);

        if (!$probe instanceof FailingDisposalProbe) {
            Fail::because(\sprintf(
                'Expected ScopeContainer::get() to return FailingDisposalProbe, got %s.',
                \get_debug_type($probe),
            ));
        }

        $probe->touch();
        $failures = $container->dispose();

        Expect::that($failures)->because('disposal failures are collected not thrown')->toHaveCount(1)
            ->and($failures[0]->getMessage())->toBe('disposal broke');
    }
}
