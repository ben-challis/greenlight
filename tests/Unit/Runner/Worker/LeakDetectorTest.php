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
    public function anExplicitModeSnapshotControlsTheEnvironmentWarning(): void
    {
        Expect::that(LeakDetector::environmentWarning(['develop']))
            ->because('Xdebug develop mode MUST explain its leak-detection false positives')
            ->toBe(
                'Warning: Xdebug develop mode keeps caught exceptions in memory. Thus, leak detection reports '
                . 'false positives. Rerun with XDEBUG_MODE=off to get correct results.',
            );
        Expect::that(LeakDetector::environmentWarning(['coverage']))
            ->because('Xdebug modes without develop MUST NOT warn about leak detection')
            ->toBeNull();
    }

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
            ->toBe([$retainedId]);
        Expect::that($detector->sweep())
            ->because('a leak MUST be reported one time')
            ->toBe([]);
    }

    #[Test]
    public function sweepReportsEveryRetainedInstanceInWatchOrder(): void
    {
        $detector = new LeakDetector();
        $firstId = new TestId('Example\FirstRetainedTest', 'retainsItself');
        $secondId = new TestId('Example\SecondRetainedTest', 'retainsItself');
        $first = new \stdClass();
        $second = new \stdClass();

        $detector->watch($firstId, $first);
        $detector->watch($secondId, $second);

        Expect::that($detector->sweep())
            ->because('a sweep MUST report every retained instance in watch order')
            ->toBe([$firstId, $secondId]);
    }
}
