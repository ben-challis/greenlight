<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Plugin\PluginRuntimeError;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Plugin\AfterTestSubscriber;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\TestAttemptEndSubscriber;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\DataProvider;
use Greenlight\Test\ExecutionPolicy;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;
use Greenlight\Tests\Fixture\Execution\Worker\Attempt\DataSetProbe;
use Greenlight\Tests\Fixture\Execution\Worker\Attempt\NoisyDisposable;
use Greenlight\Tests\Fixture\Execution\Worker\Attempt\NoisyTeardownProbe;
use Greenlight\Tests\Fixture\Execution\Worker\Attempt\PassingProbe;
use Greenlight\Tests\Fixture\Execution\Worker\Attempt\ThrowingConstructorProbe;
use Greenlight\Tests\Support\CollectingEventSink;

use function Greenlight\expect;

final readonly class TestAttemptLifecycleTest
{
    #[Test]
    public function attemptEndSubscribersRunWhenTheConstructorThrows(): void
    {
        $plugin = $this->recordingPlugin();
        [$result] = $this->run(
            [new PlanEntry(new TestDefinition(ThrowingConstructorProbe::class, 'neverRuns'))],
            [$plugin],
        );

        expect($result->outcome)->toBe(Outcome::Errored);
        expect($result->error?->message)->toBe('constructor failed');
        expect($plugin->calls)
            ->because('an attempt without a test instance MUST still release plugin per-test state')
            ->toBe(['ended ' . ThrowingConstructorProbe::class . '::neverRuns']);
    }

    #[Test]
    public function attemptEndSubscribersRunAfterAfterTestSubscribers(): void
    {
        $plugin = $this->recordingPlugin();
        [$result] = $this->run(
            [new PlanEntry(new TestDefinition(PassingProbe::class, 'passes'))],
            [$plugin],
        );

        expect($result->outcome)->toBe(Outcome::Passed);
        expect($plugin->calls)
            ->because('attempt-end subscribers MUST run after afterTest() subscribers')
            ->toBe([
                'after ' . PassingProbe::class . '::passes',
                'ended ' . PassingProbe::class . '::passes',
            ]);
    }

    #[Test]
    public function anAttemptEndFailureErrorsThePassedTestAndNamesThePlugin(): void
    {
        $failing = new class implements TestAttemptEndSubscriber, Fake {
            #[\Override]
            public function testAttemptEnded(TestId $id, TestDefinition $definition): void
            {
                throw new \RuntimeException('release failed');
            }
        };
        $recording = $this->recordingPlugin();
        [$result] = $this->run(
            [new PlanEntry(new TestDefinition(PassingProbe::class, 'passes'))],
            [$recording, $failing],
        );

        expect($result->outcome)->toBe(Outcome::Errored);
        expect($result->error?->class)->toBe(PluginRuntimeError::class);
        expect($result->error?->message)->toContain($failing::class);
        expect($result->error?->message)->toContain('testAttemptEnded');
        expect($recording->calls)
            ->because('a failed attempt-end subscriber MUST NOT stop the remaining subscribers')
            ->toContain('ended ' . PassingProbe::class . '::passes');
    }

    #[Test]
    public function captureIncludesCleanupAndTestScopeDisposal(): void
    {
        [$result] = $this->run(
            [new PlanEntry(new TestDefinition(NoisyTeardownProbe::class, 'passes'))],
            definitions: [
                new ServiceDefinition(
                    NoisyDisposable::class,
                    Scope::PerTest,
                    static fn(): NoisyDisposable => new NoisyDisposable(),
                ),
            ],
        );

        expect($result->output?->stdout)
            ->because('cleanup and per-test disposal output MUST be part of the test result')
            ->toBe('cleanup output;disposal output;');
        expect($result->output->diagnostics ?? [])->toHaveCount(1);
        expect($result->output?->diagnostics[0]->severity)
            ->because('a cleanup warning MUST reach the fail-on-warning policy')
            ->toBe(DiagnosticSeverity::Warning);
        expect($result->output?->diagnostics[0]->message)->toBe('cleanup warning');
    }

    #[Test]
    public function dataProviderExpansionIsOutsideTheTimedWindow(): void
    {
        DataSetProbe::$slowCalls = 0;
        $definition = new TestDefinition(
            DataSetProbe::class,
            'accepts',
            dataProvider: new DataProvider('slowRows'),
            execution: new ExecutionPolicy(timeoutSeconds: 0.2),
        );
        [$first, $second] = $this->run([
            new PlanEntry($definition, 'first'),
            new PlanEntry($definition, 'second'),
        ]);

        expect($first->outcome)
            ->because('a slow data provider MUST NOT consume the time limit of the first data set')
            ->toBe(Outcome::Passed);
        expect($first->durationSeconds)->toBeLessThan(0.2);
        expect($second->outcome)->toBe(Outcome::Passed);
        expect(DataSetProbe::$slowCalls)->toBe(1);
    }

    #[Test]
    public function aFailedDataProviderExpandsOnlyOnce(): void
    {
        DataSetProbe::$failingCalls = 0;
        $definition = new TestDefinition(
            DataSetProbe::class,
            'accepts',
            dataProvider: new DataProvider('failingRows'),
        );
        [$first, $second] = $this->run([
            new PlanEntry($definition, 'first'),
            new PlanEntry($definition, 'second'),
        ]);

        expect($first->outcome)->toBe(Outcome::Errored);
        expect($second->outcome)->toBe(Outcome::Errored);
        expect($second->error?->message)->toBe($first->error?->message);
        expect(DataSetProbe::$failingCalls)
            ->because('the worker MUST store a failed expansion for the method')
            ->toBe(1);
    }

    /**
     * @param list<PlanEntry> $entries
     * @param list<Plugin> $plugins
     * @param list<ServiceDefinition> $definitions
     *
     * @return list<TestResult>
     */
    private function run(array $entries, array $plugins = [], array $definitions = []): array
    {
        $sink = new CollectingEventSink();

        new Worker($definitions, WorkerPluginRuntime::fromPlugins($plugins))->run(new ExecutionPlan($entries), $sink);

        return $sink->results();
    }

    /**
     * @return AfterTestSubscriber&TestAttemptEndSubscriber&object{calls: list<string>}
     */
    private function recordingPlugin(): object
    {
        return new class implements AfterTestSubscriber, TestAttemptEndSubscriber, Fake {
            /** @var list<string> */
            public array $calls = [];

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                $this->calls[] = 'after ' . $context->id;

                return $result;
            }

            #[\Override]
            public function testAttemptEnded(TestId $id, TestDefinition $definition): void
            {
                $this->calls[] = 'ended ' . $id;
            }
        };
    }
}
