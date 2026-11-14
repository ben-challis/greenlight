<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\DataProvider;
use Greenlight\Test\ExecutionPolicy;
use Greenlight\Test\TestDefinition;
use Greenlight\Tests\Fixture\Execution\Worker\Attempt\DataSetProbe;
use Greenlight\Tests\Support\CollectingEventSink;

use function Greenlight\expect;

final readonly class DataSetExpansionTest
{
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
        [$first, $second] = $this->run($definition);

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
        [$first, $second] = $this->run(new TestDefinition(
            DataSetProbe::class,
            'accepts',
            dataProvider: new DataProvider('failingRows'),
        ));

        expect($first->outcome)->toBe(Outcome::Errored);
        expect($second->outcome)->toBe(Outcome::Errored);
        expect($second->error?->message)->toBe($first->error?->message);
        expect(DataSetProbe::$failingCalls)
            ->because('the worker MUST store a failed expansion for the method')
            ->toBe(1);
    }

    /**
     * @return list<TestResult>
     */
    private function run(TestDefinition $definition): array
    {
        $sink = new CollectingEventSink();

        new Worker([])->run(
            new ExecutionPlan([new PlanEntry($definition, 'first'), new PlanEntry($definition, 'second')]),
            $sink,
        );

        return $sink->results();
    }
}
