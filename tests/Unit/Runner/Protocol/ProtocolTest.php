<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
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
use Greenlight\Expect\Fail;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Protocol\FrameBuffer;
use Greenlight\Runner\Protocol\JsonFrameCodec;
use Greenlight\Runner\Protocol\Message;
use Greenlight\Runner\Protocol\MessageRegistry;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\AttemptStarted;
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
            new AttemptStarted(new TestId('App\FooTest', 'bar'), 2),
            new Recycling(
                RecycleReason::Memory,
                [new TestId('App\FooTest', 'bar')],
                new ResultSummary(passed: 2),
            ),
            new Done(new ResultSummary(passed: 3, failed: 1), 12345),
            new Fatal(ThrowableDetail::fromThrowable(new \RuntimeException('boom'))),
        ];

        $codec = new JsonFrameCodec();
        $buffer = new FrameBuffer();

        // Send all frames as one byte stream in three-byte parts. This shows
        // that reassembly operates across arbitrary boundaries.
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

        Expect::that($received)->because('every message survives the framed round trip')->toHaveCount(\count($messages));

        foreach ($messages as $i => $original) {
            Expect::that($received[$i]::class)->toBe($original::class);
            Expect::that($received[$i]->toWire())->toEqual($original->toWire());
        }
    }

    #[Test]
    public function assignCarriesThePlanIntact(): void
    {
        $entry = new PlanEntry(
            new TestId('App\FooTest', 'bar', 'data set one'),
            new TestMetadata('App\FooTest', 'bar', isolated: true, dataSetProvider: 'rows', resources: ['postgres']),
        );

        $assign = Assign::fromWire(new Assign(
            new ExecutionPlan([$entry], 42),
            10,
            artifactSession: new ArtifactSession('/tmp/staging', 'build/artifacts/run-1'),
            artifactConfiguration: new ArtifactConfiguration(maxRunAttachments: 123),
        )->toWire());

        Expect::that($assign->slice->seed)->because('assign carries the plan intact')->toBe(42)
            ->and($assign->recycleAfterTests)->toBe(10)
            ->and($assign->recycleAboveMemoryBytes)->toBeNull()
            ->and($assign->artifactSession?->stagingDirectory)->toBe('/tmp/staging')
            ->and($assign->artifactSession?->publicDirectory)->toBe('build/artifacts/run-1')
            ->and($assign->artifactConfiguration?->maxRunAttachments)->toBe(123)
            ->and($assign->slice->entries[0]->id->dataSetKey)->toBe('data set one')
            ->and($assign->slice->entries[0]->metadata->isolated)->toBeTrue()
            ->and($assign->slice->entries[0]->metadata->resources)->toBe(['postgres']);
    }

    #[Test]
    public function assignDropsEmptyCoverageIncludePathsFromTheWire(): void
    {
        $payload = new Assign(
            new ExecutionPlan([]),
            coverageInclude: ['/app/src'],
        )->toWire();
        $payload['coverageInclude'] = ['', '/app/src', ''];
        $assign = Assign::fromWire($payload);

        Expect::that($assign->coverageInclude)
            ->because('workers receive only non-empty coverage include paths')
            ->toBe(['/app/src']);
    }

    #[Test]
    public function oversizedFramesAreRejectedOnBothSides(): void
    {
        $codec = new JsonFrameCodec(maxFrameBytes: 64);

        Expect::that(static fn(): string => $codec->encode(['pad' => \str_repeat('x', 100)]))->because('oversized frames are rejected on both sides')
            ->toThrow(ProtocolError::class, matching: '/exceeds the 64 byte limit/');

        $buffer = new FrameBuffer(maxFrameBytes: 64);
        $buffer->feed(\pack('N', 1000));

        Expect::that(static fn(): ?string => $buffer->next())->because('oversized frames are rejected on both sides')
            ->toThrow(ProtocolError::class, matching: '/exceeds the 64 byte limit/');
    }

    #[Test]
    public function zeroLengthFramesAreRejected(): void
    {
        $buffer = new FrameBuffer();
        $buffer->feed(\pack('N', 0));

        Expect::that(static fn(): ?string => $buffer->next())
            ->because('zero-length frames are rejected')
            ->toThrow(ProtocolError::class, message: 'Malformed frame: zero-length frame.');
    }

    #[Test]
    public function unknownTagsAndVersionsAreProtocolErrors(): void
    {
        Expect::that(static fn(): Message => MessageRegistry::open(['v' => 1, 't' => 'nonsense', 'p' => []]))->because('unknown tags and versions are protocol errors')
            ->toThrow(ProtocolError::class, matching: '/Unknown message type "nonsense"/');

        Expect::that(static fn(): Message => MessageRegistry::open(['v' => 9, 't' => 'drain', 'p' => []]))->because('unknown tags and versions are protocol errors')
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
            Fail::because('Expected FrameBuffer::next() to return the complete encoded frame.');
        }

        Expect::that($codec->decode($body)['message'])->because('binary bytes in messages survive encoding')->toContain('bad');
    }
}
