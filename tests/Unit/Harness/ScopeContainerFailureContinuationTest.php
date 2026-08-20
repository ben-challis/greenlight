<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ScopeContainer;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\Harness\FailingDisposable;
use Greenlight\Tests\Fixture\Harness\RecordingDisposable;

final class ScopeContainerFailureContinuationTest
{
    #[Test]
    public function disposalContinuesAfterAServiceThrows(): void
    {
        FailingDisposable::reset();
        RecordingDisposable::reset();
        $container = new ScopeContainer();
        $recording = $container->get(new ServiceDefinition(
            RecordingDisposable::class,
            Scope::PerTest,
            static fn(): RecordingDisposable => new RecordingDisposable(),
        ));
        $failing = $container->get(new ServiceDefinition(
            FailingDisposable::class,
            Scope::PerTest,
            static fn(): FailingDisposable => new FailingDisposable(),
        ));

        Expect::that($recording)
            ->because('ScopeContainer MUST resolve RecordingDisposable.')
            ->toBeInstanceOf(RecordingDisposable::class);
        Expect::that($failing)
            ->because('ScopeContainer MUST resolve FailingDisposable.')
            ->toBeInstanceOf(FailingDisposable::class);

        $recording->initialize();
        $failing->initialize();

        $failures = $container->dispose();

        Expect::that($failures)
            ->because('a disposal failure MUST NOT prevent disposal of the remaining services')
            ->toHaveCount(1);
        Expect::that($failures[0]->getMessage())
            ->toBe('disposal broke');
        Expect::that(FailingDisposable::disposals())
            ->toBe(1);
        Expect::that(RecordingDisposable::disposals())
            ->toBe(1);
    }
}
