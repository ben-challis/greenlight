<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\FrameBuffer;
use Greenlight\Runner\Protocol\JsonFrameCodec;
use Greenlight\Runner\Protocol\Message;
use Greenlight\Runner\Protocol\MessageRegistry;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\Messages\EventEnvelope;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Recycling;
use Greenlight\Runner\Protocol\ProtocolError;

final class ProtocolTest
{
    #[Test]
    public function everyMessageSurvivesTheFramedRoundTrip(): void
    {
        $entry = new PlanEntry(
            new TestId('App\FooTest', 'bar', 'k'),
            new TestMetadata('App\FooTest', 'bar', ['slow']),
        );
        $result = new TestResult(
            new TestId('App\FooTest', 'bar'),
            Outcome::Failed,
            0.5,
            1024,
            2,
            [new FailureDetail('expected 1, got 2', '1', '2')],
        );

        $messages = [
            new Hello('w-1', 'token-abc', 4242),
            new Assign(new ExecutionPlan([$entry], 7), 500, 256 * 1024 * 1024),
            new Drain(),
            new EventEnvelope(new TestFinished($result, 1_780_000_000.5)),
            new Recycling(RecycleReason::Memory, [new TestId('App\FooTest', 'bar')]),
            new Done(new ResultSummary(passed: 3, failed: 1), 12345),
            new Fatal(ThrowableDetail::fromThrowable(new \RuntimeException('boom'))),
        ];

        $codec = new JsonFrameCodec();
        $buffer = new FrameBuffer();
        $expect = new Expect();

        // Feed all frames as one concatenated byte stream, in 3-byte chunks,
        // to prove reassembly across arbitrary boundaries.
        $stream = '';

        foreach ($messages as $message) {
            $stream .= $codec->encode(MessageRegistry::envelope($message));
        }

        $received = [];

        foreach (\str_split($stream, 3) as $chunk) {
            $buffer->feed($chunk);

            while (($body = $buffer->next()) !== null) {
                $received[] = MessageRegistry::open($codec->decode($body));
            }
        }

        $expect->that(\count($received))->toBe(\count($messages));

        foreach ($messages as $i => $original) {
            $expect->that($received[$i]::class)->toBe($original::class);
            $expect->that($received[$i]->toWire())->toEqual($original->toWire());
        }
    }

    #[Test]
    public function assignCarriesThePlanIntact(): void
    {
        $entry = new PlanEntry(
            new TestId('App\FooTest', 'bar', 'data set one'),
            new TestMetadata('App\FooTest', 'bar', isolated: true, dataSetProvider: 'rows'),
        );

        $assign = Assign::fromWire(new Assign(new ExecutionPlan([$entry], 42), 10)->toWire());

        new Expect()->that($assign->slice->seed)->toBe(42)
            ->and($assign->recycleAfterTests)->toBe(10)
            ->and($assign->recycleAboveMemoryBytes)->toBeNull()
            ->and($assign->slice->entries[0]->id->dataSetKey)->toBe('data set one')
            ->and($assign->slice->entries[0]->metadata->isolated)->toBeTrue();
    }

    #[Test]
    public function oversizedFramesAreRejectedOnBothSides(): void
    {
        $codec = new JsonFrameCodec(maxFrameBytes: 64);
        $expect = new Expect();

        $expect->that(static fn(): string => $codec->encode(['pad' => \str_repeat('x', 100)]))
            ->toThrow(ProtocolError::class, matching: '/exceeds the 64 byte limit/');

        $buffer = new FrameBuffer(maxFrameBytes: 64);
        $buffer->feed(\pack('N', 1000));

        $expect->that(static fn(): ?string => $buffer->next())
            ->toThrow(ProtocolError::class, matching: '/exceeds the 64 byte limit/');
    }

    #[Test]
    public function unknownTagsAndVersionsAreProtocolErrors(): void
    {
        $expect = new Expect();

        $expect->that(static fn(): Message => MessageRegistry::open(['v' => 1, 't' => 'nonsense', 'p' => []]))
            ->toThrow(ProtocolError::class, matching: '/Unknown message type "nonsense"/');

        $expect->that(static fn(): Message => MessageRegistry::open(['v' => 9, 't' => 'drain', 'p' => []]))
            ->toThrow(ProtocolError::class, matching: '/Unsupported protocol version 9/');
    }

    #[Test]
    public function binaryBytesInMessagesSurviveEncoding(): void
    {
        $codec = new JsonFrameCodec();
        $buffer = new FrameBuffer();
        $buffer->feed($codec->encode(['message' => "bad \xB1\x31 bytes"]));
        $body = $buffer->next();

        if ($body === null) {
            throw new \RuntimeException('Expected a complete frame.');
        }

        new Expect()->that($codec->decode($body)['message'])->toContain('bad');
    }
}
