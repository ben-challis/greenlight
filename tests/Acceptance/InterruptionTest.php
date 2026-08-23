<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Event\Event;
use Greenlight\Event\TestFinished;
use Greenlight\Event\WorkerSpawned;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Result\Outcome;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\SkipTest;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\JsonlEvents;
use Greenlight\Tests\Support\PhpSubprocess;
use Greenlight\Tests\Support\Subprocess;

final readonly class InterruptionTest
{
    private const float DEADLINE_SECONDS = 30.0;

    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    /**
     * @param list<string> $expectedDiagnostics
     */
    #[Test]
    #[DataSet('cleanupModes')]
    public function sigintDrainsWorkersAndExitsWith130(
        bool $failCleanup,
        array $expectedDiagnostics,
    ): void {
        if (!\function_exists('pcntl_signal')
            || !\function_exists('pcntl_exec')
            || !\function_exists('posix_kill')
            || !\function_exists('posix_setpgid')
        ) {
            throw new SkipTest('The process-group interruption test requires PCNTL and POSIX functions.');
        }

        $project = $this->writeProject($failCleanup);
        $tmp = $this->tempDirectory->subdirectory('interrupt/tmp');
        $markerDir = $project->path('markers');
        $root = \dirname(__DIR__, 2);
        $process = PhpSubprocess::start(
            $project->directory,
            [
                \dirname(__DIR__) . '/Fixture/Cli/Signal/ProcessGroupLauncher.php',
                $root . '/bin/greenlight',
                'run',
                '--workers=2',
                '--reporter=jsonl',
            ],
            ['TMPDIR' => $tmp],
        );
        $this->cleanup->defer($process->terminate(...));

        $deadline = \microtime(true) + self::DEADLINE_SECONDS;

        // A marker makes standard output available before the buffer is
        // full. A test-finished line can delay SIGINT until after the run
        // ends.
        while (\microtime(true) < $deadline && \glob($markerDir . '/*.child-started') === []) {
            $process->pump();
            \usleep(5_000);
        }

        if (\glob($markerDir . '/*.child-started') === []) {
            Fail::because(\sprintf(
                'Timed out after %.1fs waiting for a fixture subprocess to start.',
                self::DEADLINE_SECONDS,
            ));
        }

        $process->signalProcessGroup(\SIGINT);
        $result = $process->wait(self::DEADLINE_SECONDS);

        $events = JsonlEvents::from($result);
        $finished = \array_values(\array_filter(
            $events,
            static fn(Event $event): bool => $event instanceof TestFinished,
        ));

        Expect::that($result->stdout)
            ->because('The interrupted run MUST report a finished test.')
            ->toContain('"test-finished"');
        Expect::that($finished)
            ->because('The interrupted run MUST finish an active test.')
            ->not()
            ->toBeEmpty();

        foreach ($finished as $event) {
            Expect::that($event->result->outcome)
                ->because('Terminal SIGINT MUST NOT fail a test subprocess.')
                ->toBe(Outcome::Passed);
        }

        Expect::that($result->exitCode)
            ->because('SIGINT MUST produce exit code 130.')
            ->toBe(130);

        foreach ($expectedDiagnostics as $diagnostic) {
            Expect::that($result->stderr)
                ->because('SIGINT MUST report each interruption diagnostic.')
                ->toContain($diagnostic);
        }

        $workerPids = $this->spawnedWorkerPids($events);
        Expect::that($workerPids)
            ->because('The interrupted run MUST start at least one worker.')
            ->not()
            ->toBeEmpty();

        foreach ($workerPids as $pid) {
            $alive = Subprocess::run($root, ['ps', '-p', (string) $pid, '-o', 'pid=']);
            Expect::that(\trim($alive->stdout))
                ->because(\sprintf('Worker process %d MUST NOT exist after the run exits.', $pid))
                ->toBe('');
        }

        $sockets = \glob($tmp . '/greenlight-*/orchestrator.sock');
        Expect::that(\is_array($sockets) ? $sockets : [])
            ->because('The interrupted run MUST remove its orchestrator socket.')
            ->toBe([]);
        $cleaned = \file($markerDir . '/cleaned.log', \FILE_IGNORE_NEW_LINES);
        Expect::that(\is_array($cleaned) ? $cleaned : [])
            ->because('The interrupted run MUST clean its integration fixtures.')
            ->toBe(['cleaned']);
        $resources = \glob($markerDir . '/resource-*');
        Expect::that(\is_array($resources) ? $resources : [])
            ->because('The interrupted run MUST remove its integration fixture resources.')
            ->toBe([]);
    }

    /**
     * @return iterable<string, array{bool, list<string>}>
     */
    public static function cleanupModes(): iterable
    {
        yield 'successful cleanup' => [
            false,
            ['Interrupted'],
        ];
        yield 'failed cleanup' => [
            true,
            [
                'Integration fixture teardown failed.',
                'intentional fixture cleanup failure',
                'Interrupted. Integration fixture teardown was attempted before exit.',
            ],
        ];
    }

    /**
     * @param list<Event> $events
     *
     * @return list<int>
     */
    private function spawnedWorkerPids(array $events): array
    {
        $pids = [];

        foreach ($events as $event) {
            if ($event instanceof WorkerSpawned) {
                $pids[] = $event->pid;
            }
        }

        return $pids;
    }

    private function writeProject(bool $failCleanup): AcceptanceProject
    {
        $project = AcceptanceProject::create(
            $this->tempDirectory,
            'interrupt-' . ($failCleanup ? 'failed-cleanup' : 'successful-cleanup'),
        );
        $project->writeFile('markers/.gitkeep', '');
        $markerDir = $project->path('markers');

        // Each class starts a signal-aware subprocess. The subprocess writes
        // a marker before it waits. The parent sends SIGINT after the first marker.
        $template = <<<'PHP_WRAP'
        <?php

        declare(strict_types=1);

        namespace InterruptProbe;

        use Greenlight\Attribute\Test;

        final class %sTest
        {
            #[Test]
            public function one(): void
            {
                $process = \proc_open(
                    [
                        \PHP_BINARY,
                        '-r',
                        '\\pcntl_async_signals(true); '
                            . '\\pcntl_signal(\\SIGINT, static fn() => exit(130)); '
                            . '\\file_put_contents($argv[1], "1"); '
                            . '\\usleep(200_000);',
                        %s . '/%sTest.child-started',
                    ],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );

                if (!\is_resource($process)) {
                    throw new \RuntimeException('Could not start the fixture subprocess.');
                }

                foreach ($pipes as $pipe) {
                    \fclose($pipe);
                }

                $exitCode = \proc_close($process);

                if ($exitCode !== 0) {
                    throw new \RuntimeException(\sprintf(
                        'The fixture subprocess exited with code %%d.',
                        $exitCode,
                    ));
                }
            }

            #[Test]
            public function two(): void { self::settle(); }

            #[Test]
            public function three(): void { self::settle(); }

            #[Test]
            public function four(): void { self::settle(); }

            #[Test]
            public function five(): void { self::settle(); }

            private static function settle(): void
            {
                $deadline = \microtime(true) + 0.05;

                while (\microtime(true) < $deadline) {
                    \usleep(5_000);
                }
            }
        }
        PHP_WRAP;

        $files = [];

        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot'] as $name) {
            $file = \sprintf('tests/%sTest.php', $name);
            $project->writeFile($file, \sprintf(
                $template,
                $name,
                \var_export($markerDir, true),
                $name,
            ));
            $files[] = $file;
        }

        $requires = \implode("\n", \array_map(
            static fn(string $file): string => "require_once __DIR__ . '/{$file}';",
            $files,
        ));
        $markerDirectory = \var_export($markerDir, true);
        $failCleanupValue = $failCleanup ? 'true' : 'false';

        $project->writeFile('greenlight.php', <<<PHP
            <?php

            declare(strict_types=1);

            use Greenlight\\Config\\GreenlightConfig;
            use Greenlight\\Tests\\Fixture\\Plugins\\IntegrationProbePlugin;

            {$requires}

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(2)
                ->plugins(
                    static fn(): IntegrationProbePlugin => new IntegrationProbePlugin(
                        {$markerDirectory},
                        failCleanup: {$failCleanupValue},
                    ),
                );
            PHP);

        return $project;
    }
}
