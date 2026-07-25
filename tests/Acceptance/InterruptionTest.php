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

        // POSIX-only beyond the pcntl check above: the test shells out to
        // `kill -INT` and `ps -p` directly, neither of which exists on
        // Windows.

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

            // A marker avoids stdout block buffering. Waiting for a
            // test-finished line can delay SIGINT until the run has ended.
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

            Expect::that($result->stdout)->toContain('"test-finished"')
                ->and($result->exitCode)->toBe(130)
                ->and($result->stderr)->toContain('Interrupted');

            foreach ($this->spawnedWorkerPids(JsonlEvents::from($result)) as $pid) {
                $alive = Subprocess::run($root, ['ps', '-p', (string) $pid, '-o', 'pid=']);
                Expect::that(\trim($alive->stdout))->toBe('');
            }

            $sockets = \glob($tmp . '/greenlight-*/orchestrator.sock');
            $cleaned = \file($markerDir . '/cleaned.log', \FILE_IGNORE_NEW_LINES);
            $resources = \glob($markerDir . '/resource-*');
            Expect::that(\is_array($sockets) ? $sockets : [])->toBe([])
                ->and(\is_array($cleaned) ? $cleaned : [])->toBe(['cleaned'])
                ->and(\is_array($resources) ? $resources : [])->toBe([]);
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
        $project->write('markers/.gitkeep', '');
        $markerDir = $project->path('markers');

        // Each class writes a marker when work starts. The parent can send
        // SIGINT without relying on a fixed startup delay, while the bounded
        // loop keeps the class busy long enough to receive it.
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
            $project->write($file, \sprintf(
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

        $project->write('greenlight.php', <<<PHP
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
