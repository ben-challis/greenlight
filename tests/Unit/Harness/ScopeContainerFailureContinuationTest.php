<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ScopeContainer;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\Harness\FailingDisposable;
use Greenlight\Tests\Fixture\Harness\RecordingDisposable;

use function Greenlight\expect;

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

        expect($recording)
            ->because('ScopeContainer MUST resolve RecordingDisposable.')
            ->toBeInstanceOf(RecordingDisposable::class);
        expect($failing)
            ->because('ScopeContainer MUST resolve FailingDisposable.')
            ->toBeInstanceOf(FailingDisposable::class);

        $recording->initialize();
        $failing->initialize();

        $failures = $container->dispose();

        expect($failures)
            ->because('a disposal failure MUST NOT prevent disposal of the remaining services')
            ->toHaveCount(1);
        expect($failures[0]->getMessage())
            ->toBe('disposal broke');
        expect(FailingDisposable::disposals())
            ->toBe(1);
        expect(RecordingDisposable::disposals())
            ->toBe(1);
    }
}
