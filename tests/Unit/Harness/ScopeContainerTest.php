<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ScopeContainer;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\Lifecycle\Services\ServiceProbe;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final class ScopeContainerTest
{
    #[Test]
    public function reusesTheServiceWithinTheScope(): void
    {
        $expect = new Expect();
        $container = new ScopeContainer();
        $definition = new ServiceDefinition(\ArrayObject::class, Scope::PerTest, static fn(): \ArrayObject => new \ArrayObject());

        $first = $container->get($definition);
        $second = $container->get($definition);

        $expect->that($second)->toBe($first);
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

        new Expect()->that($failures)->toBe([])->and(TraceLog::drain())->toBe([]);
    }

    #[Test]
    public function touchedServicesDisposeInReverseCreationOrder(): void
    {
        ServiceProbe::reset();
        TraceLog::drain();
        $expect = new Expect();

        $container = new ScopeContainer();
        $probeDefinition = new ServiceDefinition(ServiceProbe::class, Scope::PerTest, static fn(): ServiceProbe => new ServiceProbe());
        $otherDefinition = new ServiceDefinition(\ArrayObject::class, Scope::PerTest, static fn(): \ArrayObject => new \ArrayObject());

        $probe = $container->get($probeDefinition);
        $container->get($otherDefinition);

        if (!$probe instanceof ServiceProbe) {
            throw new \RuntimeException('Container returned the wrong type.');
        }

        $probe->touch();
        $container->dispose();

        $expect->that(TraceLog::drain())->toBe(['probe1:created', 'probe1:touched', 'probe1:disposed']);
    }

    #[Test]
    public function disposalFailuresAreCollectedNotThrown(): void
    {
        $container = new ScopeContainer();
        $definition = new ServiceDefinition(
            \Greenlight\Tests\Fixture\Lifecycle\DisposeFails\FailingDisposalProbe::class,
            Scope::PerTest,
            static fn(): \Greenlight\Tests\Fixture\Lifecycle\DisposeFails\FailingDisposalProbe => new \Greenlight\Tests\Fixture\Lifecycle\DisposeFails\FailingDisposalProbe(),
        );

        $probe = $container->get($definition);

        if (!$probe instanceof \Greenlight\Tests\Fixture\Lifecycle\DisposeFails\FailingDisposalProbe) {
            throw new \RuntimeException('Container returned the wrong type.');
        }

        $probe->touch();
        $failures = $container->dispose();

        new Expect()->that(\count($failures))->toBe(1)
            ->and($failures[0]->getMessage())->toBe('disposal broke');
    }
}
