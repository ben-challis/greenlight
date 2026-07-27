<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Worker\LeakDetector;

final class LeakDetectorTest
{
    #[Test]
    public function sweepDropsCollectedInstancesAndReportsRetainedInstancesOnce(): void
    {
        $detector = new LeakDetector();
        $retainedId = new TestId('Example\RetainedTest', 'retainsItself');
        $collectedId = new TestId('Example\CollectedTest', 'releasesItself');
        $retained = new \stdClass();
        $collected = new \stdClass();

        $detector->watch($retainedId, $retained);
        $detector->watch($collectedId, $collected);
        unset($collected);

        Expect::that($detector->sweep())
            ->because('a sweep MUST report only instances that remain alive')
            ->toBe([$retainedId])
            ->and($detector->sweep())
            ->because('a leak MUST be reported one time')
            ->toBe([]);
    }
}
