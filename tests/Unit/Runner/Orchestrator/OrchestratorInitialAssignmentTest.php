<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Event\WorkerTiming;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\InitialWorkerAssignment;
use Greenlight\Runner\Orchestrator\Orchestrator;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\NativeOrchestrator;
use Greenlight\Tests\Support\PlanEntryFixture;

final readonly class OrchestratorInitialAssignmentTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    #[Timeout(30.0)]
    public function progressiveStartupBeginsBeforeTheSlowestWorkerAndKeepsTheFirstWaveFair(): void
    {
        $directory = $this->tempDirectory->subdirectory('progressive');
        $orchestrator = $this->orchestrator($directory, InitialWorkerAssignment::Progressive);

        $orchestrator->run($this->plan(), new CollectingEventSink(), 2);

        $firstWorker = $this->assignments($directory, 'w-1');
        $secondWorker = $this->assignments($directory, 'w-2');
        $slowReadyAt = (float) \file_get_contents($directory . '/slow-ready');
        $firstWorkerTiming = \array_find(
            $orchestrator->workerTimings(),
            static fn(WorkerTiming $timing): bool => $timing->workerId === 'w-1',
        );

        if ($firstWorkerTiming === null) {
            throw new \LogicException('The first worker timing record is missing.');
        }

        Expect::that($firstWorker[0]['at'])
            ->because('useful work SHOULD start before the slowest initial worker is ready')
            ->toBeLessThan($slowReadyAt);
        $fastWorkerAssignmentsBeforeSlowReady = \array_values(\array_filter(
            $firstWorker,
            static fn(array $assignment): bool => $assignment['at'] < $slowReadyAt,
        ));

        Expect::that(\array_column($fastWorkerAssignmentsBeforeSlowReady, 'class'))
            ->because('the fast worker MUST NOT take a second assignment before the other intended worker is ready')
            ->toBe(['FirstTest']);
        Expect::that($secondWorker[0]['class'])
            ->because('each intended initial worker MUST receive one fair first assignment')
            ->toBe('SecondTest');

        $assignedClasses = [...\array_column($firstWorker, 'class'), ...\array_column($secondWorker, 'class')];
        \sort($assignedClasses);

        Expect::that($assignedClasses)
            ->because('either ready worker MAY receive work after the fair first wave')
            ->toBe(['FirstTest', 'SecondTest', 'ThirdTest']);
        Expect::that($firstWorkerTiming->bootstrapBarrierSeconds)
            ->because('profile output MUST attribute the fair first-wave wait to bootstrap coordination')
            ->toBeGreaterThan(0.0);
    }

    #[Test]
    #[Timeout(30.0)]
    public function explicitBarrierWaitsForEveryInitialWorker(): void
    {
        $directory = $this->tempDirectory->subdirectory('barrier');
        $orchestrator = $this->orchestrator($directory, InitialWorkerAssignment::AfterAllReady);

        $orchestrator->run($this->plan(), new CollectingEventSink(), 2);

        $firstWorker = $this->assignments($directory, 'w-1');
        $slowReadyAt = (float) \file_get_contents($directory . '/slow-ready');

        Expect::that($firstWorker[0]['at'])
            ->because('the explicit initial barrier MUST preserve worker bootstrap lifecycle guarantees')
            ->toBeGreaterThan($slowReadyAt);
    }

    #[Test]
    #[Timeout(30.0)]
    public function resourceCapacityAvoidsStartingWorkersThatCannotRun(): void
    {
        $directory = $this->tempDirectory->subdirectory('resource-capacity');
        $sink = new CollectingEventSink();
        $orchestrator = $this->orchestrator($directory, InitialWorkerAssignment::Progressive, slowSecondWorker: false);

        $orchestrator->run($this->plan(['database']), $sink, 8);

        $spawned = \array_values(\array_filter(
            $sink->events,
            static fn(object $event): bool => $event instanceof WorkerSpawned,
        ));

        Expect::that($spawned)
            ->because('exclusive resource capacity proves that only one worker can run')
            ->toHaveCount(1);
    }

    /**
     * @param list<non-empty-string> $resources
     */
    private function plan(array $resources = []): ExecutionPlan
    {
        return new ExecutionPlan([
            PlanEntryFixture::create('FirstTest', resources: $resources),
            PlanEntryFixture::create('SecondTest', resources: $resources),
            PlanEntryFixture::create('ThirdTest', resources: $resources),
        ]);
    }

    private function orchestrator(
        string $directory,
        InitialWorkerAssignment $assignment,
        bool $slowSecondWorker = true,
    ): Orchestrator {
        $encodedDirectory = \base64_encode($directory);
        $delay = $slowSecondWorker ? 500_000 : 0;
        $script = \sprintf(
            <<<'PHP'
                [, , $address, $workerId, $token] = $argv;
                $directory = base64_decode(%s, true);
                $socket = stream_socket_client($address);

                $send = static function (array $message) use ($socket): void {
                    $json = json_encode($message, JSON_THROW_ON_ERROR);
                    fwrite($socket, pack('N', strlen($json)) . $json);
                    fflush($socket);
                };
                $read = static function (int $length) use ($socket): string {
                    $bytes = '';

                    while (strlen($bytes) < $length) {
                        $chunk = fread($socket, $length - strlen($bytes));

                        if ($chunk === false || $chunk === '') {
                            exit(1);
                        }

                        $bytes .= $chunk;
                    }

                    return $bytes;
                };
                $receive = static function () use ($read): array {
                    $length = unpack('Nlength', $read(4))['length'];

                    return json_decode($read($length), true, flags: JSON_THROW_ON_ERROR);
                };

                $send([
                    'v' => 2,
                    't' => 'hello',
                    'p' => ['workerId' => $workerId, 'token' => $token, 'pid' => getmypid()],
                ]);
                $receive();

                if ($workerId === 'w-2') {
                    usleep(%d);
                    file_put_contents($directory . '/slow-ready', (string) microtime(true));
                }

                $send(['v' => 2, 't' => 'ready', 'p' => []]);

                while (true) {
                    $message = $receive();

                    if ($message['t'] === 'drain') {
                        exit(0);
                    }

                    $class = $message['p']['slice']['entries'][0]['id']['class'];
                    file_put_contents(
                        $directory . '/' . $workerId . '.jsonl',
                        json_encode(['class' => $class, 'at' => microtime(true)], JSON_THROW_ON_ERROR) . "\n",
                        FILE_APPEND,
                    );
                    $send([
                        'v' => 2,
                        't' => 'done',
                        'p' => [
                            'summary' => ['passed' => 0, 'failed' => 0, 'errored' => 0, 'skipped' => 0],
                            'peakMemoryBytes' => 0,
                            'coverage' => null,
                            'leaks' => [],
                            'wantsRecycle' => null,
                        ],
                    ]);
                }
                PHP,
            \var_export($encodedDirectory, true),
            $delay,
        );

        return NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, '-r', $script],
            workingDirectory: $directory,
            resourceLimits: ['database' => 1],
            initialWorkerAssignment: $assignment,
        );
    }

    /**
     * @return list<array{class: string, at: float}>
     */
    private function assignments(string $directory, string $workerId): array
    {
        $lines = \file($directory . '/' . $workerId . '.jsonl', \FILE_IGNORE_NEW_LINES);

        if (!\is_array($lines)) {
            return [];
        }

        $assignments = [];

        foreach ($lines as $line) {
            $decoded = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            $class = \is_array($decoded) ? ($decoded['class'] ?? null) : null;
            $at = \is_array($decoded) ? ($decoded['at'] ?? null) : null;

            if (!\is_string($class) || !\is_float($at)) {
                throw new \LogicException('The worker assignment record is malformed.');
            }

            $assignments[] = ['class' => $class, 'at' => $at];
        }

        return $assignments;
    }
}
