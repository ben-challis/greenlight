<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Event\TestFinished;
use Greenlight\Execution\Artifact\ArtifactSession;
use Greenlight\Execution\ProcessPool\Protocol\FrameBuffer;
use Greenlight\Execution\ProcessPool\Protocol\JsonFrameCodec;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\MessageRegistry;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Assign;
use Greenlight\Execution\ProcessPool\Protocol\Messages\AttemptStarted;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Done;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Ready;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\FixtureResource;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultPolicy;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\DataProvider;
use Greenlight\Test\SchedulingPolicy;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;

final class ProtocolTest
{
    #[Test]
    public function everyMessageSurvivesTheFramedRoundTrip(): void
    {
        $entry = new PlanEntry(
            new TestDefinition('App\FooTest', 'bar', ['slow']),
            'k',
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
            new Bootstrap(2, '/project/greenlight.php', new IntegrationResources([
                'postgres' => FixtureResource::from(
                    ['database' => 'test_2'],
                    ['password' => 'secret'],
                ),
            ]), policy: new ResultPolicy(failOnRisky: true)),
            new Ready(),
            new Assign(new ExecutionPlan([$entry], 7)),
            new Drain(),
            new EventEnvelope(new TestFinished($result, 1_780_000_000.5)),
            new AttemptStarted(new TestId('App\FooTest', 'bar'), 2),
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
            new TestDefinition(
                'App\FooTest',
                'bar',
                dataProvider: new DataProvider('rows'),
                scheduling: new SchedulingPolicy(isolated: true, resources: ['postgres']),
            ),
            'data set one',
        );

        $assign = Assign::fromWire(new Assign(
            new ExecutionPlan([$entry], 42),
            artifactSession: new ArtifactSession('/tmp/staging', 'build/artifacts/run-1'),
            artifactConfiguration: new ArtifactConfiguration(maxRunAttachments: 123),
            stopAfterFailures: 2,
        )->toWire());

        Expect::that($assign->slice->seed)->because('assign carries the plan intact')->toBe(42);
        Expect::that($assign->artifactSession?->stagingDirectory)->toBe('/tmp/staging');
        Expect::that($assign->artifactSession?->publicDirectory)->toBe('build/artifacts/run-1');
        Expect::that($assign->artifactConfiguration?->maxRunAttachments)->toBe(123);
        Expect::that($assign->stopAfterFailures)->toBe(2);
        Expect::that($assign->slice->entries[0]->id->dataSetKey)->toBe('data set one');
        Expect::that($assign->slice->entries[0]->definition->scheduling->isolated)->toBeTrue();
        Expect::that($assign->slice->entries[0]->definition->scheduling->resources)->toBe(['postgres']);
    }

    #[Test]
    public function assignDropsOnlyEmptyCoverageIncludePathsFromTheWire(): void
    {
        $payload = new Assign(
            new ExecutionPlan([]),
            coverageInclude: ['/app/src'],
        )->toWire();
        $payload['coverageInclude'] = ['', '0', '/app/src', ''];
        $assign = Assign::fromWire($payload);

        Expect::that($assign->coverageInclude)
            ->because('workers MUST retain each non-empty coverage include path')
            ->toBe(['0', '/app/src']);
    }

    #[Test]
    public function legacyAssignmentPayloadsDefaultArtifactFields(): void
    {
        $payload = new Assign(new ExecutionPlan([]))->toWire();
        unset($payload['artifactSession'], $payload['artifactConfiguration'], $payload['stopAfterFailures']);

        $assign = Assign::fromWire($payload);

        Expect::that($assign->artifactSession)
            ->because('legacy assignments have no artifact session')
            ->toBeNull();
        Expect::that($assign->artifactConfiguration)
            ->because('legacy assignments have no artifact configuration')
            ->toBeNull();
        Expect::that($assign->stopAfterFailures)
            ->because('legacy assignments have no local failure allowance')
            ->toBeNull();
    }

    #[Test]
    public function legacyBootstrapPayloadsDefaultTheResultPolicy(): void
    {
        $payload = new Bootstrap(1, null, new IntegrationResources())->toWire();
        unset($payload['policy']);

        Expect::that(Bootstrap::fromWire($payload)->policy)
            ->because('legacy bootstrap payloads do not contain a result policy')
            ->toBeNull();
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
    public function stalledWorkerErrorsIncludeCapturedOutput(): void
    {
        $error = ProtocolError::workerStalled(
            'worker-7',
            2.5,
            "stderr: extension failed\nstdout: booting",
        );

        Expect::that($error->getMessage())
            ->because('a stalled worker error MUST preserve its captured output')
            ->toBe(
                "Worker \"worker-7\" sent no message for 2.5 seconds after connection. No test was active. "
                . "The worker stopped responding between protocol messages. Greenlight stopped the run to prevent an unlimited wait.\n"
                . "Worker output:\n"
                . "stderr: extension failed\n"
                . 'stdout: booting',
            );
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

        Expect::that($body)
            ->because('FrameBuffer::next() MUST return the complete encoded frame.')
            ->not()
            ->toBeNull();

        Expect::that($codec->decode($body)['message'])->because('binary bytes in messages survive encoding')->toContain('bad');
    }
}
