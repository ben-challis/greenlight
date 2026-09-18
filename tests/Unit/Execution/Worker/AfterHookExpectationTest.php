<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Expect\Expect;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\FixturePath;

final readonly class AfterHookExpectationTest
{
    #[Test]
    public function anAfterHookExpectationFailureRetainsItsComparison(): void
    {
        $result = $this->results()['passesUntilTeardown'];

        Expect::value($result->outcome)->toBe(Outcome::Failed);
        Expect::value($result->error)->toBeNull();
        Expect::value($result->failures)->toHaveCount(1);
        Expect::value($result->failures[0]->expected)->toBe("'expected'");
        Expect::value($result->failures[0]->actual)->toBe("'actual'");
        Expect::value($result->expectations)->toBe(1);
    }

    #[Test]
    public function anAfterHookExpectationFailureOverridesASkip(): void
    {
        $result = $this->results()['skipsBeforeTeardown'];

        Expect::value($result->outcome)->toBe(Outcome::Failed);
        Expect::value($result->skipReason)->toBeNull();
        Expect::value($result->error)->toBeNull();
        Expect::value($result->failures[0]->expected)->toBe("'expected'");
        Expect::value($result->failures[0]->actual)->toBe("'actual'");
    }

    #[Test]
    public function anEarlierAssertionFailureRemainsPrimary(): void
    {
        $result = $this->results()['failsBeforeTeardown'];

        Expect::value($result->outcome)->toBe(Outcome::Failed);
        Expect::value($result->error)->toBeNull();
        Expect::value($result->failures)->toHaveCount(1);
        Expect::value($result->failures[0]->expected)->toBe("'body expected'");
        Expect::value($result->failures[0]->actual)->toBe("'body actual'");
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
