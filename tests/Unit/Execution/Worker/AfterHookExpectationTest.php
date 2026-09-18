<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\FixturePath;

use function Greenlight\expect;

final readonly class AfterHookExpectationTest
{
    #[Test]
    public function anAfterHookExpectationFailureRetainsItsComparison(): void
    {
        $result = $this->results()['passesUntilTeardown'];

        expect($result->outcome)->toBe(Outcome::Failed);
        expect($result->error)->toBeNull();
        expect($result->failures)->toHaveCount(1);
        expect($result->failures[0]->expected)->toBe("'expected'");
        expect($result->failures[0]->actual)->toBe("'actual'");
        expect($result->expectations)->toBe(1);
    }

    #[Test]
    public function anAfterHookExpectationFailureOverridesASkip(): void
    {
        $result = $this->results()['skipsBeforeTeardown'];

        expect($result->outcome)->toBe(Outcome::Failed);
        expect($result->skipReason)->toBeNull();
        expect($result->error)->toBeNull();
        expect($result->failures[0]->expected)->toBe("'expected'");
        expect($result->failures[0]->actual)->toBe("'actual'");
    }

    #[Test]
    public function anEarlierAssertionFailureRemainsPrimary(): void
    {
        $result = $this->results()['failsBeforeTeardown'];

        expect($result->outcome)->toBe(Outcome::Failed);
        expect($result->error)->toBeNull();
        expect($result->failures)->toHaveCount(1);
        expect($result->failures[0]->expected)->toBe("'body expected'");
        expect($result->failures[0]->actual)->toBe("'body actual'");
    }

    /** @return array<string, TestResult> */
    private function results(): array
    {
        $plan = new TestDiscoverer()->discover([FixturePath::get('Lifecycle/AfterExpectationFails')]);
        $sink = new CollectingEventSink();

        new Worker([])->run($plan, $sink);

        $results = [];

        foreach ($sink->results() as $result) {
            $results[$result->id->method] = $result;
        }

        return $results;
    }
}
