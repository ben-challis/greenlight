<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Test\TestDefinition;
use Greenlight\Tests\Fixture\Execution\Worker\Attempt\NoisyDisposable;
use Greenlight\Tests\Fixture\Execution\Worker\Attempt\NoisyTeardownProbe;
use Greenlight\Tests\Support\CollectingEventSink;

use function Greenlight\expect;

final readonly class CaptureWindowTest
{
    #[Test]
    public function captureIncludesCleanupAndTestScopeDisposal(): void
    {
        $sink = new CollectingEventSink();
        $definitions = [
            new ServiceDefinition(
                NoisyDisposable::class,
                Scope::PerTest,
                static fn(): NoisyDisposable => new NoisyDisposable(),
            ),
        ];

        new Worker($definitions)->run(
            new ExecutionPlan([new PlanEntry(new TestDefinition(NoisyTeardownProbe::class, 'passes'))]),
            $sink,
        );
        $result = $sink->results()[0];

        expect($result->output?->stdout)
            ->because('cleanup and per-test disposal output MUST be part of the test result')
            ->toBe('cleanup output;disposal output;');
        expect($result->output->diagnostics ?? [])->toHaveCount(1);
        expect($result->output?->diagnostics[0]->severity)
            ->because('a cleanup warning MUST reach the fail-on-warning policy')
            ->toBe(DiagnosticSeverity::Warning);
        expect($result->output?->diagnostics[0]->message)->toBe('cleanup warning');
    }
}
