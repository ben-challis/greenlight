<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Condition;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Runner\Worker\Worker;
use Greenlight\Tests\Fixture\Condition\ThrowingCondition;
use Greenlight\Tests\Fixture\Lifecycle\ConditionArguments\ConditionArgumentsTest;
use Greenlight\Tests\Support\CollectingEventSink;

final class ConditionEvaluationTest
{
    #[Test]
    public function parameterizedConditionsSkipWithRenderedArgumentsAndRunWhenSatisfied(): void
    {
        \putenv('GREENLIGHT_STDLIB_NOPE');
        [$summary, $results] = $this->runFixture('ConditionArguments');

        $byMethod = [];

        foreach ($results as $result) {
            $byMethod[$result->id->method] = $result;
        }

        Expect::that($summary->skipped)->because('parameterized conditions skip with rendered arguments and run when satisfied')->toBe(1)
            ->and($summary->passed)->toBe(1)
            ->and($byMethod['skipsWhenTheVariableDiffers']->outcome)->toBe(Outcome::Skipped)
            ->and($byMethod['skipsWhenTheVariableDiffers']->skipReason)
            ->toBe('Condition EnvironmentVariableEquals("GREENLIGHT_STDLIB_NOPE", "yes") is not satisfied.')
            ->and($byMethod['runsWhenTheVersionIsSatisfied']->outcome)->toBe(Outcome::Passed);
    }

    /**
     * @param non-empty-string $conditionClass
     * @param non-empty-string $message
     */
    #[Test]
    #[DataSet('invalidConditions')]
    public function invalidRuntimeConditionMetadataErrorsTheTest(string $conditionClass, string $message): void
    {
        $id = new TestId(ConditionArgumentsTest::class, 'runsWhenTheVersionIsSatisfied');
        $plan = new ExecutionPlan([
            new PlanEntry(
                $id,
                new TestMetadata(
                    $id->class,
                    $id->method,
                    skipUnlessCondition: $conditionClass,
                ),
            ),
        ]);
        $sink = new CollectingEventSink();

        new Worker(new HarnessRegistry(), PluginRegistry::forWorker([]))
            ->run($plan, $sink);

        $result = $sink->results()[0];

        Expect::that($result->outcome)
            ->because('invalid runtime condition metadata errors the test')
            ->toBe(Outcome::Errored)
            ->and($result->error?->message)
            ->toBe($message);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function invalidConditions(): iterable
    {
        yield 'missing class' => [
            'Example\MissingCondition',
            'Condition class "Example\MissingCondition" does not exist.',
        ];

        yield 'wrong interface' => [
            \stdClass::class,
            \sprintf(
                'Condition class "%s" does not implement %s.',
                \stdClass::class,
                Condition::class,
            ),
        ];

        yield 'throws while evaluating' => [
            ThrowingCondition::class,
            'condition evaluation failed',
        ];
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
