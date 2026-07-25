<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;
use Greenlight\Tests\Support\Subprocess;

final readonly class InterruptionTest
{
    private const float DEADLINE_SECONDS = 30.0;

    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function sigintDrainsWorkersAndExitsWith130(): void
    {
        if (!\function_exists('pcntl_signal')) {
            throw new SkipTest('Graceful interruption requires ext-pcntl in the CLI PHP.');
        }

        // The pcntl check is not sufficient on Windows. This test directly
        // runs `kill -INT` and `ps -p`, which Windows does not provide.

        $project = $this->writeProject();
        $tmp = $this->tempDirectory->subdirectory('interrupt/tmp');
        $markerDir = $project->path('markers');
        $root = \dirname(__DIR__, 2);
        $process = GreenlightCli::start(
            $project->directory,
            ['run', '--workers=2', '--reporter=jsonl'],
            ['TMPDIR' => $tmp],
        );

        try {
            $deadline = \microtime(true) + self::DEADLINE_SECONDS;

            // A marker makes standard output available before the buffer is
            // full. A test-finished line can delay SIGINT until after the run
            // ends.
            while (\microtime(true) < $deadline && \glob($markerDir . '/*.started') === []) {
                $process->pump();
                \usleep(5_000);
            }

            if (\glob($markerDir . '/*.started') === []) {
                Fail::because(\sprintf(
                    'Timed out after %.1fs waiting for a fixture test to start.',
                    self::DEADLINE_SECONDS,
                ));
            }

            $process->signal(\SIGINT);
            $result = $process->wait(self::DEADLINE_SECONDS);

            Expect::that($result->stdout)
                ->because('The interrupted run MUST report a finished test.')
                ->toContain('"test-finished"');
            Expect::that($result->exitCode)
                ->because('SIGINT MUST produce exit code 130.')
                ->toBe(130);
            Expect::that($result->stderr)
                ->because('SIGINT MUST report the interruption.')
                ->toContain('Interrupted');

            $workerPids = $this->spawnedWorkerPids(JsonlEvents::from($result));
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
        } finally {
            $process->terminate();
        }
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

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'interrupt');
        $project->writeFile('markers/.gitkeep', '');
        $markerDir = $project->path('markers');

        // Each class writes a marker when work starts. The parent sends SIGINT
        // without a fixed start delay. The bounded loop keeps the class active
        // long enough to receive the signal.
        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace InterruptProbe;

            use Greenlight\Attribute\Test;

            final class %sTest
            {
                #[Test]
                public function one(): void
                {
                    \file_put_contents(%s . '/%sTest.started', '1');
                    self::settle();
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
            PHP;

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

        $project->writeFile('greenlight.php', <<<PHP
            <?php

            declare(strict_types=1);

            use Greenlight\\Config\\GreenlightConfig;
            use Greenlight\\Tests\\Fixture\\Plugins\\IntegrationProbePlugin;

            {$requires}

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(2)
                ->plugins(new IntegrationProbePlugin({$markerDirectory}));
            PHP);

        return $project;
    }
}
