<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Plugin\PluginRuntimeError;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Execution\Worker\DefaultServices;
use Greenlight\Execution\Worker\HarnessServiceDisposal;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Expectation;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Plugin\AfterTestSubscriber;
use Greenlight\Plugin\BeforeTestSubscriber;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\RetryDecider;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\RetryPolicy;
use Greenlight\Test\SkipTest;
use Greenlight\Test\TestChannel;
use Greenlight\Test\TestId;
use Greenlight\Tests\Fixture\Lifecycle\Services\ServiceProbe;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;
use Greenlight\Tests\Fixture\Plugins\EvenNumbersExtension;
use Greenlight\Tests\Fixture\Plugins\ProbeProvider;
use Greenlight\Tests\Fixture\Plugins\QuarantinePlugin;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\FixturePath;

final readonly class PluginTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function aPluginCannotReplaceADefaultHarnessService(): void
    {
        $provider = new class implements HarnessProvider, Fake {
            #[\Override]
            public function services(): array
            {
                return [
                    new ServiceDefinition(
                        TemporaryDirectory::class,
                        Scope::PerTest,
                        static fn(): TemporaryDirectory => new TemporaryDirectory(),
                    ),
                ];
            }
        };
        $plugins = WorkerPluginRuntime::fromPlugins([$provider]);

        Expect::that(static fn() => $plugins->prepareWorker(
            new WorkerBootstrapContext('test-worker', new TestChannel(1), new IntegrationResources()),
            DefaultServices::definitions(),
        ))
            ->because('plugin services MUST not replace Greenlight-owned defaults')
            ->toThrow(
                \LogicException::class,
                message: 'A harness service for Greenlight\Sandbox\TemporaryDirectory is already registered.',
            );
    }

    #[Test]
    public function quarantinePluginTransformsFailuresWithProvenance(): void
    {
        [, $results] = $this->runSuite('PluginRunSuite', [new QuarantinePlugin()]);

        $byMethod = [];

        foreach ($results as $result) {
            $byMethod[$result->id->method] = $result;
        }

        $quarantined = $byMethod['flakyAndQuarantined'];

        Expect::that($quarantined->outcome)->because('quarantine plugin transforms failures with provenance')->toBe(Outcome::Skipped);
        Expect::that($quarantined->transformations)->toHaveCount(1);
        Expect::that($quarantined->transformations[0]->transformedBy)->toBe(QuarantinePlugin::class);
        Expect::that($quarantined->transformations[0]->from)->toBe(Outcome::Errored);
        Expect::that($quarantined->transformations[0]->to)->toBe(Outcome::Skipped);
        Expect::that($byMethod['passes']->outcome)->toBe(Outcome::Passed);
    }

    #[Test]
    public function unattributedOutcomeChangesErrorTheTestNamingThePlugin(): void
    {
        $rogue = new class implements AfterTestSubscriber, Fake {
            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                // Bypass withOutcome() intentionally to omit the transformation
                // source.
                return new TestResult($result->id, Outcome::Skipped, $result->durationSeconds, 0);
            }
        };

        [, $results] = $this->runSuite('Lifecycle/Order', [$rogue]);
        $message = \sprintf(
            'Plugin "%s" changed the outcome from passed to skipped without a new '
            . 'transformation-log entry from withOutcome().',
            $rogue::class,
        );

        Expect::that($results[0]->outcome)
            ->because('unattributed outcome changes error the test naming the plugin')
            ->toBe(Outcome::Errored);
        Expect::that($results[0]->error?->message)
            ->toBe($message);
        Expect::that($results[0]->error?->class)->toBe(PluginRuntimeError::class);
    }

    #[Test]
    public function afterTestCannotReplaceTheTestIdentity(): void
    {
        $rogue = new class implements AfterTestSubscriber, Fake {
            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                return new TestResult(
                    new TestId('Rogue\\InjectedTest', 'wrong'),
                    $result->outcome,
                    $result->durationSeconds,
                    $result->memoryDeltaBytes,
                );
            }
        };

        [, $results] = $this->runSuite('Lifecycle/Order', [$rogue]);
        $result = $results[0];
        $expectedId = 'Greenlight\\Tests\\Fixture\\Lifecycle\\Order\\OrderTest::theTest';

        Expect::that((string) $result->id)
            ->because('afterTest() MUST NOT replace the executed test identity')
            ->toBe($expectedId);
        Expect::that($result->outcome)
            ->toBe(Outcome::Errored);
        Expect::that($result->error?->message)
            ->toBe(\sprintf(
                'Plugin "%s" changed the test identity during afterTest() from "%s" to "Rogue\\InjectedTest::wrong".',
                $rogue::class,
                $expectedId,
            ));
        Expect::that($result->error?->class)->toBe(PluginRuntimeError::class);
    }

    #[Test]
    public function throwingBeforeTestErrorsTheTestNamingThePlugin(): void
    {
        $broken = new class implements BeforeTestSubscriber, Fake {
            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                throw new \RuntimeException('plugin exploded');
            }
        };

        [, $results] = $this->runSuite('Lifecycle/Order', [$broken]);
        $message = \sprintf(
            'Plugin "%s" caused an error during beforeTest(): plugin exploded',
            $broken::class,
        );

        Expect::that($results[0]->outcome)
            ->because('throwing before test errors the test naming the plugin')
            ->toBe(Outcome::Errored);
        Expect::that($results[0]->error?->message)
            ->toBe($message);
        Expect::that($results[0]->error?->class)->toBe(PluginRuntimeError::class);
    }

    #[Test]
    public function throwingAfterTestKeepsTheOutcomeAndRecordsThePluginFailure(): void
    {
        $broken = new class implements AfterTestSubscriber, Fake {
            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                throw new \RuntimeException('plugin exploded');
            }
        };

        [, $results] = $this->runSuite('RunFailingSuite', [$broken]);
        $pluginFailure = \sprintf(
            'Plugin "%s" caused an error during afterTest(): plugin exploded',
            $broken::class,
        );

        $byMethod = [];

        foreach ($results as $result) {
            $byMethod[$result->id->method] = $result;
        }

        // The passed test becomes an error that names the plugin.
        Expect::that($byMethod['passes']->outcome)
            ->because('throwing after test keeps the outcome and records the plugin failure')
            ->toBe(Outcome::Errored);
        Expect::that($byMethod['passes']->error?->message)
            ->toBe($pluginFailure);
        Expect::that($byMethod['passes']->error?->class)->toBe(PluginRuntimeError::class);

        // The test keeps its original error. Greenlight records the plugin
        // failure as a failure detail.
        $errored = $byMethod['explodes'];
        Expect::that($errored->outcome)
            ->because('throwing after test keeps the outcome and records the plugin failure')
            ->toBe(Outcome::Errored);
        Expect::that($errored->error?->message)
            ->toContain('intentional boom');
        Expect::that($errored->failures[0]->message ?? '')
            ->toBe($pluginFailure);

        // The test keeps its assertion failure. Greenlight adds the plugin
        // failure after it and does not replace either failure with an error.
        [, $failedResults] = $this->runSuite('PluginAssertionFailure', [$broken]);
        $failed = $failedResults[0];
        Expect::that($failed->outcome)
            ->because('a plugin failure MUST NOT replace an assertion failure')
            ->toBe(Outcome::Failed);
        Expect::that($failed->error)
            ->toBe(null);
        Expect::that($failed->failures)
            ->toHaveCount(2);
        Expect::that($failed->failures[0]->message)
            ->toContain('intentional assertion failure');
        Expect::that($failed->failures[1]->message)
            ->toBe($pluginFailure);
    }

    #[Test]
    public function throwingRetryDeciderErrorsTheFailedTest(): void
    {
        $broken = new class implements RetryDecider, Fake {
            #[\Override]
            public function shouldRetry(
                RetryPolicy $policy,
                TestResult $result,
                int $attempt,
                ?\Throwable $cause,
            ): bool {
                throw new \RuntimeException('retry decision failed');
            }
        };

        [, $results] = $this->runSuite('RunFailingSuite', [$broken]);

        $byMethod = [];

        foreach ($results as $result) {
            $byMethod[$result->id->method] = $result;
        }

        Expect::that($byMethod['passes']->outcome)
            ->because('a retry decider MUST NOT run after a successful test')
            ->toBe(Outcome::Passed);
        Expect::that($byMethod['explodes']->outcome)
            ->because('a retry decider failure MUST error the unsuccessful test')
            ->toBe(Outcome::Errored);
        Expect::that($byMethod['explodes']->error?->message)
            ->toBe('retry decision failed');
    }

    #[Test]
    public function contextSkipFromBeforeTestSkipsTheTest(): void
    {
        $skipper = new class implements BeforeTestSubscriber {
            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                $context->skip('flaky on this platform');
            }
        };

        [$summary, $results] = $this->runSuite('Lifecycle/Order', [$skipper]);

        Expect::that($summary->skipped)->because('context skip from before test skips the test')->toBe(1);
        Expect::that($results[0]->skipReason)->toBe('flaky on this platform');
    }

    #[Test]
    public function pluginSkipBypassesExecutionButRunsEveryTeardown(): void
    {
        TraceLog::drain();

        $skipper = new class implements AfterTestSubscriber, BeforeTestSubscriber, Fake {
            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                $context->skip('not applicable');
            }

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                TraceLog::add('plugin-after');

                return $result;
            }
        };

        [$summary] = $this->runSuite('Lifecycle/Order', [$skipper]);

        Expect::that($summary->skipped)
            ->because('the plugin skip stops test execution')
            ->toBe(1);
        Expect::that(TraceLog::drain())
            ->because('the plugin skip MUST preserve fixture and subscriber teardown order')
            ->toBe(['construct', 'after2', 'after1', 'plugin-after']);
    }

    #[Test]
    public function skipSignalFromBeforeTestSkipsTheTest(): void
    {
        $skipper = new class implements BeforeTestSubscriber {
            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                throw new SkipTest('quarantined environment');
            }
        };

        [$summary, $results] = $this->runSuite('Lifecycle/Order', [$skipper]);

        Expect::that($summary->skipped)->because('skip signal from before test skips the test')->toBe(1);
        Expect::that($results[0]->skipReason)->toBe('quarantined environment');
    }

    #[Test]
    public function subscribersRunInPriorityOrderAndUnwindInReverse(): void
    {
        TraceLog::drain();

        $late = new class implements AfterTestSubscriber, BeforeTestSubscriber, Prioritized {
            #[\Override]
            public function priority(): int
            {
                return 10;
            }

            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                TraceLog::add('late:before');
            }

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                TraceLog::add('late:after');

                return $result;
            }
        };

        $early = new class implements AfterTestSubscriber, BeforeTestSubscriber, Prioritized {
            #[\Override]
            public function priority(): int
            {
                return -10;
            }

            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                TraceLog::add('early:before');
            }

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                TraceLog::add('early:after');

                return $result;
            }
        };

        $this->runSuite('Lifecycle/Order', [$late, $early]);

        Expect::that(TraceLog::drain())
            ->because('test subscribers MUST enter by priority and unwind in reverse')
            ->toBe([
                'construct',
                'early:before',
                'late:before',
                'before1',
                'before2',
                'test',
                'after2',
                'after1',
                'late:after',
                'early:after',
            ]);
    }

    #[Test]
    public function harnessProvidersContributeInjectableServices(): void
    {
        ServiceProbe::reset();
        TraceLog::drain();

        [$summary] = $this->runSuite('Lifecycle/Services', [new ProbeProvider()]);

        Expect::that($summary->passed)->because('harness providers contribute injectable services')->toBe(2);
        Expect::that(TraceLog::drain())->toContain('probe1:disposed');
    }

    #[Test]
    public function expectationExtensionsDispatchThroughTheChain(): void
    {
        $restoreExtensions = Expect::install([new EvenNumbersExtension()]);
        $this->cleanup->defer($restoreExtensions);

        Expect::that(4)->toBeEven();
        Expect::that(3)->not()->toBeEven();

        Expect::that(static function (): void {
            Expect::that(3)->toBeEven();
        })->toThrow(ExpectationFailed::class, matching: '/extension matcher toBeEven/');

        Expect::that(static fn(): Expectation => Expect::that(3)->__call('toBeSomethingUnknown', []))
            ->toThrow(\BadMethodCallException::class, matching: '/toBeSomethingUnknown/');
    }

    /**
     * @param list<Plugin> $plugins
     *
     * @return array{ResultSummary, list<TestResult>}
     */
    private function runSuite(string $fixture, array $plugins): array
    {
        $directory = FixturePath::get($fixture);
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink = new CollectingEventSink();
        $runtime = WorkerPluginRuntime::fromPlugins($plugins);
        $definitions = DefaultServices::definitions();
        $scopes = $runtime->prepareWorker(
            new WorkerBootstrapContext('test-worker', new TestChannel(1), new IntegrationResources()),
            $definitions,
        );
        $outcome = HarnessServiceDisposal::runAndClose(
            $scopes,
            static fn() => new Worker($definitions, $runtime)->run($plan, $sink, scopes: $scopes),
        );

        return [$outcome->summary, $sink->results()];
    }
}
