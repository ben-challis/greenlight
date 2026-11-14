<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

/**
 * Lists the phases of one test attempt in execution order.
 *
 * The capture and timing window opens before the first phase. Data-set
 * expansion occurs after output capture starts and before the attempt clock
 * starts.
 *
 * A phase that does not always run is a test-body phase. A test-body phase
 * runs only while the attempt has no skip, failure, or error. A throwable from
 * a test-body phase gives the attempt an error.
 *
 * A phase that always runs is a teardown phase. Teardown phases also run when
 * an earlier phase throws. After the last phase, the executor throws the last
 * throwable from a teardown phase.
 *
 * @internal
 */
enum AttemptPhase
{
    case Instantiate;
    case BeforeTestSubscribers;
    case TestMethod;
    case AfterHooks;
    case CleanupCallbacks;
    case CloseTestScope;
    case LeaveExpectationWindow;
    case StopCapture;

    public function insideWindow(): bool
    {
        return $this !== self::StopCapture;
    }

    public function alwaysRuns(): bool
    {
        return match ($this) {
            self::Instantiate, self::BeforeTestSubscribers, self::TestMethod => false,
            default => true,
        };
    }
}
