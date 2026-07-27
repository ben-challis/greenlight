<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Expectation;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\TestLifecycleSubscriber;
use Greenlight\Runner\DefaultServices;
use Greenlight\Runner\Worker\Worker;
use Greenlight\Tests\Fixture\Lifecycle\Services\ServiceProbe;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;
use Greenlight\Tests\Fixture\Plugins\EvenNumbersExtension;
use Greenlight\Tests\Fixture\Plugins\ProbeProvider;
use Greenlight\Tests\Fixture\Plugins\QuarantinePlugin;
use Greenlight\Tests\Support\CollectingEventSink;

final class PluginTest
{
    #[Test]
    public function quarantinePluginTransformsFailuresWithProvenance(): void
    {
        [, $results] = $this->runSuite('PluginRunSuite', [new QuarantinePlugin()]);

        $byMethod = [];

        foreach ($results as $result) {
            $byMethod[$result->id->method] = $result;
        }

        $quarantined = $byMethod['flakyAndQuarantined'];

        Expect::that($quarantined->outcome)->because('quarantine plugin transforms failures with provenance')->toBe(Outcome::Skipped)
            ->and($quarantined->transformations[0]->transformedBy)->toBe(QuarantinePlugin::class)
            ->and($quarantined->transformations[0]->from)->toBe(Outcome::Errored)
            ->and($byMethod['passes']->outcome)->toBe(Outcome::Passed);
    }

    #[Test]
    public function unattributedOutcomeChangesErrorTheTestNamingThePlugin(): void
    {
        $rogue = new class implements TestLifecycleSubscriber {
            #[\Override]
            public function beforeTest(TestContext $context): void {}

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                // Bypass withOutcome() intentionally to omit the transformation
                // source.
                return new TestResult($result->id, Outcome::Skipped, $result->durationSeconds, 0);
            }
        };

        [, $results] = $this->runSuite('Lifecycle/Order', [$rogue]);

        Expect::that($results[0]->outcome)->because('unattributed outcome changes error the test naming the plugin')->toBe(Outcome::Errored)
            ->and($results[0]->error?->message)->toContain('without a new transformation-log entry from withOutcome()');
    }

    #[Test]
    public function throwingBeforeTestErrorsTheTestNamingThePlugin(): void
    {
        $broken = new class implements TestLifecycleSubscriber {
            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                throw new \RuntimeException('plugin exploded');
            }

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                return $result;
            }
        };

        [, $results] = $this->runSuite('Lifecycle/Order', [$broken]);

        Expect::that($results[0]->outcome)->because('throwing before test errors the test naming the plugin')->toBe(Outcome::Errored)
            ->and($results[0]->error?->message)->toContain('caused an error during beforeTest()')
            ->toContain('plugin exploded');
    }

    #[Test]
    public function throwingAfterTestKeepsTheOutcomeAndRecordsThePluginFailure(): void
    {
        $broken = new class implements TestLifecycleSubscriber {
            #[\Override]
            public function beforeTest(TestContext $context): void {}

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                throw new \RuntimeException('plugin exploded');
            }
        };

        [, $results] = $this->runSuite('RunFailingSuite', [$broken]);

        $byMethod = [];

        foreach ($results as $result) {
            $byMethod[$result->id->method] = $result;
        }

        // The passed test becomes an error that names the plugin.
        Expect::that($byMethod['passes']->outcome)->because('throwing after test keeps the outcome and records the plugin failure')->toBe(Outcome::Errored)
            ->and($byMethod['passes']->error?->message)->toContain('caused an error during afterTest()')
            ->toContain('plugin exploded');

        // The test keeps its original error. Greenlight records the plugin
        // failure as a failure detail.
        $errored = $byMethod['explodes'];
        Expect::that($errored->outcome)->because('throwing after test keeps the outcome and records the plugin failure')->toBe(Outcome::Errored)
            ->and($errored->error?->message)->toContain('intentional boom')
            ->and($errored->failures[0]->message ?? '')->toContain('caused an error during afterTest()')
            ->toContain('plugin exploded');
    }

    #[Test]
    public function contextSkipFromBeforeTestSkipsTheTest(): void
    {
        $skipper = new class implements TestLifecycleSubscriber {
            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                $context->skip('flaky on this platform');
            }

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                return $result;
            }
        };

        [$summary, $results] = $this->runSuite('Lifecycle/Order', [$skipper]);

        Expect::that($summary->skipped)->because('context skip from before test skips the test')->toBe(1)
            ->and($results[0]->skipReason)->toBe('flaky on this platform');
    }

    #[Test]
    public function skipSignalFromBeforeTestSkipsTheTest(): void
    {
        $skipper = new class implements TestLifecycleSubscriber {
            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                throw new SkipTest('quarantined environment');
            }

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                return $result;
            }
        };

        [$summary, $results] = $this->runSuite('Lifecycle/Order', [$skipper]);

        Expect::that($summary->skipped)->because('skip signal from before test skips the test')->toBe(1)
            ->and($results[0]->skipReason)->toBe('quarantined environment');
    }

    #[Test]
    public function subscribersRunInPriorityOrder(): void
    {
        TraceLog::drain();

        $late = new class implements TestLifecycleSubscriber, Prioritized {
            #[\Override]
            public function priority(): int
            {
                return 10;
            }

            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                TraceLog::add('late');
            }

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                return $result;
            }
        };

        $early = new class implements TestLifecycleSubscriber, Prioritized {
            #[\Override]
            public function priority(): int
            {
                return -10;
            }

            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                TraceLog::add('early');
            }

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                return $result;
            }
        };

        $this->runSuite('Lifecycle/Order', [$late, $early]);

        // Construction occurs before beforeTest(). Thus, the first entry comes
        // from the fixture.
        Expect::that(\array_slice(TraceLog::drain(), 1, 2))->because('subscribers run in priority order')->toBe(['early', 'late']);
    }

    #[Test]
    public function harnessProvidersContributeInjectableServices(): void
    {
        ServiceProbe::reset();
        TraceLog::drain();

        [$summary] = $this->runSuite('Lifecycle/Services', [new ProbeProvider()]);

        Expect::that($summary->passed)->because('harness providers contribute injectable services')->toBe(2)
            ->and(TraceLog::drain())->toContain('probe1:disposed');
    }

    #[Test]
    public function expectationExtensionsDispatchThroughTheChain(): void
    {
        Expect::install([new EvenNumbersExtension()]);

        try {
            // Dispatch uses __call because static analysis cannot resolve dynamic
            // matchers.
            Expect::that(4)->__call('toBeEven', []);
            Expect::that(3)->not()->__call('toBeEven', []);

            Expect::that(static function (): void {
                Expect::that(3)->__call('toBeEven', []);
            })->toThrow(ExpectationFailed::class, matching: '/extension matcher toBeEven/');

            Expect::that(static fn(): Expectation => Expect::that(3)->__call('toBeSomethingUnknown', []))
                ->toThrow(\BadMethodCallException::class, matching: '/toBeSomethingUnknown/');
        } finally {
            Expect::install([]);
        }
    }

    /**
     * @param list<Plugin> $plugins
     *
     * @return array{ResultSummary, list<TestResult>}
     */
    private function runSuite(string $fixture, array $plugins): array
    {
        $directory = \dirname(__DIR__, 2) . '/Fixture/' . $fixture;
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink = new CollectingEventSink();
        $registry = PluginRegistry::forWorker($plugins);

        $outcome = new Worker(DefaultServices::registry($registry), $registry)->run($plan, $sink);

        return [$outcome->summary, $sink->results()];
    }
}
