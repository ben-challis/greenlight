<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\Messages\AttemptStarted;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Hello;

final class WorkerMessageWireTest
{
    #[Test]
    public function helloNormalizesANonpositiveProcessId(): void
    {
        $hello = Hello::fromWire([
            'workerId' => 'worker-1',
            'token' => 'run-token',
            'pid' => 0,
        ]);

        Expect::that($hello->pid)
            ->because('a decoded worker process ID MUST be positive')
            ->toBe(1);
    }

    #[Test]
    public function attemptStartedNormalizesANonpositiveAttempt(): void
    {
        $attempt = AttemptStarted::fromWire([
            'id' => new TestId('App\ExampleTest', 'checksValue')->toWire(),
            'attempt' => -3,
        ]);

        Expect::that($attempt->attempt)
            ->because('a decoded attempt number MUST be positive')
            ->toBe(1);
    }

    #[Test]
    public function doneNormalizesMemoryAndDecodesRecycleIntent(): void
    {
        $summary = new ResultSummary();
        $withoutRecycle = Done::fromWire([
            'summary' => $summary->toWire(),
            'peakMemoryBytes' => -100,
            'coverage' => null,
            'leaks' => [],
            'wantsRecycle' => '',
        ]);
        $withRecycle = Done::fromWire([
            'summary' => $summary->toWire(),
            'peakMemoryBytes' => 2048,
            'coverage' => null,
            'leaks' => [],
            'wantsRecycle' => RecycleReason::TestCount->value,
        ]);

        Expect::that($withoutRecycle->peakMemoryBytes)
            ->because('decoded peak memory MUST NOT be negative')
            ->toBe(0)
            ->and($withoutRecycle->wantsRecycle)->toBeNull();
        Expect::that($withRecycle->peakMemoryBytes)
            ->because('valid Done fields MUST survive decoding')
            ->toBe(2048)
            ->and($withRecycle->wantsRecycle)->toBe(RecycleReason::TestCount);
    }

    #[Test]
    public function doneRejectsAnUnknownRecycleReasonAsAProtocolError(): void
    {
        $payload = new Done(new ResultSummary(), 2048)->toWire();
        $payload['wantsRecycle'] = 'unknown';

        Expect::that(static fn(): Done => Done::fromWire($payload))
            ->because('unknown worker recycle reasons MUST be protocol errors')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "wantsRecycle" must be a Greenlight\Core\Event\RecycleReason value, got string.',
            );
    }
}
