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

/**
 * Interrupts a real bin/greenlight run with SIGINT and asserts the clean
 * shutdown contract: exit code 130, the interrupted marker, no orphaned
 * worker processes, and no leaked orchestrator socket directory. The run
 * gets a private TMPDIR so temp-dir assertions cannot race other tests.
 */
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

            // A marker file written straight to disk at the top of the
            // first test method fires as soon as any test has started,
            // with none of the block-buffering delay a "test-finished"
            // line in the piped stdout would carry: under CPU pressure the
            // whole run could otherwise finish before that line is ever
            // observed, sending SIGINT after there is nothing left to
            // interrupt.
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
            Expect::that(\is_array($sockets) ? $sockets : [])->toBe([]);
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

        // The first test of every class touches a marker as soon as it
        // starts, so the parent test can send SIGINT the moment any work
        // is underway rather than guessing at a sleep long enough to
        // still be running when it checks. The remaining sleeps only need
        // to keep the class occupied a little longer than the round trip
        // to deliver the signal takes; a short deadline-bounded loop
        // keeps that bounded instead of resting on one blind usleep.
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

        $project->writeConfig($files);

        return $project;
    }
}
