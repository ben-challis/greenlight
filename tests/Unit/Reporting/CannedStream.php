<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\SuiteFinished;
use Greenlight\Core\Event\SuiteStarted;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\SourceLocation;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestId;
use Greenlight\Reporting\Reporter;

/**
 * The shared canned event stream for reporter golden tests: two classes, a
 * pass, a failure with expected and actual, a slow pass, an error, a skip,
 * and a retried pass, plus worker spawn and recycle events. Timestamps and
 * durations are fixed so every reporter's output is deterministic.
 */
final class CannedStream
{
    private function __construct() {}

    public static function feed(Reporter $reporter): void
    {
        foreach (self::events() as $event) {
            $reporter->onEvent($event);
        }

        $reporter->finish();
    }

    /**
     * @return list<Event>
     */
    public static function events(): array
    {
        $at = 1_750_000_000.5;

        $calc = 'Acme\CalculatorTest';
        $network = 'Acme\NetworkTest';

        $adds = new TestId($calc, 'addsIntegers');
        $subtracts = new TestId($calc, 'subtractsIntegers');
        $multiplies = new TestId($calc, 'multipliesIntegers', 'large numbers');
        $connects = new TestId($network, 'connects');
        $pings = new TestId($network, 'pings');
        $retries = new TestId($network, 'retriesFlakyEndpoint');

        $failed = new TestResult(
            $subtracts,
            Outcome::Failed,
            0.02,
            2048,
            failures: [
                new FailureDetail(
                    'Failed asserting that two values are equal.',
                    '2',
                    '3',
                    new SourceLocation('/project/tests/CalculatorTest.php', 42),
                ),
            ],
        );

        $errored = new TestResult(
            $connects,
            Outcome::Errored,
            0.005,
            4096,
            error: new ThrowableDetail(
                'RuntimeException',
                'Connection refused.',
                '/project/tests/NetworkTest.php',
                17,
                ['Acme\NetworkTest::connect at /project/tests/NetworkTest.php:17'],
            ),
        );

        $skipped = new TestResult(
            $pings,
            Outcome::Skipped,
            0.0,
            0,
            skipReason: 'Requires ext-redis.',
        );

        return [
            new RunStarted('run-1', 6, 2, $at),
            new WorkerSpawned('w-1', 101, $at + 0.01),
            new WorkerSpawned('w-2', 102, $at + 0.02),
            new SuiteStarted('unit', $at + 0.03),
            new TestClassStarted($calc, $at + 0.04),
            new TestStarted($adds, $at + 0.05),
            new TestFinished(new TestResult($adds, Outcome::Passed, 0.012, 1024), $at + 0.06),
            new TestStarted($subtracts, $at + 0.07),
            new TestFinished($failed, $at + 0.08),
            new TestStarted($multiplies, $at + 0.09),
            new TestFinished(new TestResult($multiplies, Outcome::Passed, 0.34, 262144), $at + 0.1),
            new TestClassFinished($calc, $at + 0.11),
            new TestClassStarted($network, $at + 0.12),
            new TestStarted($connects, $at + 0.13),
            new TestFinished($errored, $at + 0.14),
            new TestStarted($pings, $at + 0.15),
            new TestFinished($skipped, $at + 0.16),
            new TestStarted($retries, $at + 0.17),
            new TestFinished(new TestResult($retries, Outcome::Passed, 0.15, 512, attempts: 3), $at + 0.18),
            new TestClassFinished($network, $at + 0.19),
            new WorkerRecycled('w-1', RecycleReason::Memory, $at + 0.2),
            new SuiteFinished('unit', $at + 0.21),
            new RunFinished(
                'run-1',
                new ResultSummary(passed: 3, failed: 1, errored: 1, skipped: 1),
                1.234,
                $at + 0.22,
            ),
        ];
    }
}
