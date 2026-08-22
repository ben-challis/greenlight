<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\Messages\AttemptStarted;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Hello;

final class WorkerMessageWireTest
{
    #[Test]
    #[DataSet('nonpositiveIntegers')]
    public function helloNormalizesANonpositiveProcessId(int $number): void
    {
        $hello = Hello::fromWire([
            'workerId' => 'worker-1',
            'token' => 'run-token',
            'pid' => $number,
        ]);

        Expect::that($hello->pid)
            ->because('a decoded worker process ID MUST be positive')
            ->toBe(1);
    }

    #[Test]
    #[DataSet('nonpositiveIntegers')]
    public function attemptStartedNormalizesANonpositiveAttempt(int $number): void
    {
        $attempt = AttemptStarted::fromWire([
            'id' => new TestId('App\ExampleTest', 'checksValue')->toWire(),
            'attempt' => $number,
        ]);

        Expect::that($attempt->attempt)
            ->because('a decoded attempt number MUST be positive')
            ->toBe(1);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonpositiveIntegers(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
    }

    #[Test]
    public function doneNormalizesMemory(): void
    {
        $summary = new ResultSummary();
        $done = Done::fromWire([
            'summary' => $summary->toWire(),
            'peakMemoryBytes' => -100,
            'coverage' => null,
            'leaks' => [],
        ]);

        Expect::that($done->peakMemoryBytes)
            ->because('decoded peak memory MUST NOT be negative')
            ->toBe(0);
    }
}
