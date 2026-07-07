<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Expectation;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\SkipTest;
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

        new Expect()->that($quarantined->outcome)->toBe(Outcome::Skipped)
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
                // Deliberately bypasses withOutcome(): no provenance.
                return new TestResult($result->id, Outcome::Skipped, $result->durationSeconds, 0);
            }
        };

        [, $results] = $this->runSuite('Lifecycle/Order', [$rogue]);

        new Expect()->that($results[0]->outcome)->toBe(Outcome::Errored)
            ->and($results[0]->error?->message)->toContain('without withOutcome() provenance');
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

        new Expect()->that($results[0]->outcome)->toBe(Outcome::Errored)
            ->and($results[0]->error?->message)->toContain('failed in beforeTest')
            ->and($results[0]->error?->message)->toContain('plugin exploded');
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

        new Expect()->that($summary->skipped)->toBe(1)
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

        new Expect()->that($summary->skipped)->toBe(1)
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

        // Construction precedes beforeTest, so the first entry is the fixture's own.
        new Expect()->that(\array_slice(TraceLog::drain(), 1, 2))->toBe(['early', 'late']);
    }

    #[Test]
    public function harnessProvidersContributeInjectableServices(): void
    {
        ServiceProbe::reset();
        TraceLog::drain();

        [$summary] = $this->runSuite('Lifecycle/Services', [new ProbeProvider()]);

        new Expect()->that($summary->passed)->toBe(2)
            ->and(TraceLog::drain())->toContain('probe1:disposed');
    }

    #[Test]
    public function expectationExtensionsDispatchThroughTheChain(): void
    {
        $expect = new Expect([new EvenNumbersExtension()]);

        // Dispatch is exercised through __call directly: static analysis
        // cannot know dynamic matchers, and typed autocomplete for extensions
        // is a GA-time concern.
        $expect->that(4)->__call('toBeEven', []);
        $expect->that(3)->not()->__call('toBeEven', []);

        new Expect()->that(static function () use ($expect): void {
            $expect->that(3)->__call('toBeEven', []);
        })->toThrow(ExpectationFailed::class, matching: '/extension matcher toBeEven/');

        new Expect()->that(static fn(): Expectation => $expect->that(3)->__call('toBeSomethingUnknown', []))
            ->toThrow(\BadMethodCallException::class, matching: '/toBeSomethingUnknown/');
    }

    /**
     * @param list<object> $plugins
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
