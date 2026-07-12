<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Runner\Worker\Worker;
use Greenlight\Tests\Support\CollectingEventSink;

final class ConditionEvaluationTest
{
    #[Test]
    public function parameterisedConditionsSkipWithRenderedArgumentsAndRunWhenSatisfied(): void
    {
        \putenv('GREENLIGHT_STDLIB_NOPE');
        [$summary, $results] = $this->runFixture('ConditionArguments');

        $byMethod = [];

        foreach ($results as $result) {
            $byMethod[$result->id->method] = $result;
        }

        Expect::that($summary->skipped)->toBe(1)
            ->and($summary->passed)->toBe(1)
            ->and($byMethod['skipsWhenTheVariableDiffers']->outcome)->toBe(Outcome::Skipped)
            ->and($byMethod['skipsWhenTheVariableDiffers']->skipReason)
            ->toBe('Condition EnvironmentVariableEquals("GREENLIGHT_STDLIB_NOPE", "yes") is not satisfied.')
            ->and($byMethod['runsWhenTheVersionIsSatisfied']->outcome)->toBe(Outcome::Passed);
    }

    /**
     * @return array{ResultSummary, list<TestResult>}
     */
    private function runFixture(string $case): array
    {
        $directory = \dirname(__DIR__, 2) . '/Fixture/Lifecycle/' . $case;
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink = new CollectingEventSink();

        $outcome = new Worker(new HarnessRegistry(), PluginRegistry::forWorker([]))
            ->run($plan, $sink);

        return [$outcome->summary, $sink->results()];
    }
}
