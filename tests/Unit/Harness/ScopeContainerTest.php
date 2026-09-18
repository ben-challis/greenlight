<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ScopeContainer;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\Harness\FailingDisposable;
use Greenlight\Tests\Fixture\Lifecycle\DisposeFails\FailingDisposalProbe;
use Greenlight\Tests\Fixture\Lifecycle\Services\SecondaryServiceProbe;
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

        Expect::value($second)->because('reuses the service within the scope')->toBe($first);
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

        Expect::value($failures)->because('an untouched lazy service is never constructed nor disposed')->toBe([]);
        Expect::value(TraceLog::drain())->toBe([]);
    }

    #[Test]
    public function touchedServicesDisposeInReverseCreationOrder(): void
    {
        ServiceProbe::reset();
        TraceLog::drain();

        $container = new ScopeContainer();
        $probeDefinition = new ServiceDefinition(
            ServiceProbe::class,
            Scope::PerTest,
            static fn(): ServiceProbe => new ServiceProbe(),
        );
        $secondaryDefinition = new ServiceDefinition(
            SecondaryServiceProbe::class,
            Scope::PerTest,
            static fn(): SecondaryServiceProbe => new SecondaryServiceProbe(),
        );

        $probe = $container->get($probeDefinition);
        $secondary = $container->get($secondaryDefinition);

        Expect::value($probe)
            ->because('ScopeContainer::get() MUST return ServiceProbe.')
            ->toBeInstanceOf(ServiceProbe::class);
        Expect::value($secondary)
            ->because('ScopeContainer::get() MUST return SecondaryServiceProbe.')
            ->toBeInstanceOf(SecondaryServiceProbe::class);

        $probe->touch();
        $secondary->touch();
        $firstFailures = $container->dispose();
        $secondFailures = $container->dispose();

        Expect::value($firstFailures)
            ->because('disposing touched services succeeds')
            ->toBe([]);
        Expect::value($secondFailures)
            ->because('a disposed scope does not dispose its services twice')
            ->toBe([]);
        Expect::value(TraceLog::drain())
            ->because('touched services dispose in reverse creation order')
            ->toBe([
                'probe1:created',
                'probe1:touched',
                'secondary:created',
                'secondary:touched',
                'secondary:disposed',
                'probe1:disposed',
            ]);
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

        Expect::value($probe)
            ->because('ScopeContainer::get() MUST return FailingDisposalProbe.')
            ->toBeInstanceOf(FailingDisposalProbe::class);

        $probe->touch();
        $failures = $container->dispose();

        Expect::value($failures)->because('disposal failures are collected not thrown')->toHaveCount(1);
        Expect::value($failures[0]->getMessage())->toBe('disposal broke');
    }

    #[Test]
    public function aFailedDisposalIsNotRetried(): void
    {
        FailingDisposable::reset();

        $container = new ScopeContainer();
        $definition = new ServiceDefinition(
            FailingDisposable::class,
            Scope::PerTest,
            static fn(): FailingDisposable => new FailingDisposable(),
        );
        $service = $container->get($definition);

        Expect::value($service)
            ->because('ScopeContainer::get() MUST return FailingDisposable.')
            ->toBeInstanceOf(FailingDisposable::class);

        $service->initialize();
        $first = $container->dispose();
        $second = $container->dispose();

        Expect::value($first)
            ->because('the first disposal reports the service failure')
            ->toHaveCount(1);
        Expect::value($first[0]->getMessage())
            ->toBe('disposal broke');
        Expect::value($second)
            ->because('a failed disposal MUST still leave the scope empty')
            ->toBe([]);
        Expect::value(FailingDisposable::disposals())
            ->toBe(1);
    }
}
