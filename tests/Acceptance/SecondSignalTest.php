<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\SkipTest;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\PhpSubprocess;
use Greenlight\Tests\Support\Subprocess;

use function Greenlight\expect;

final readonly class SecondSignalTest
{
    private const float DEADLINE_SECONDS = 30.0;

    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function aSecondSigintKillsWorkersThatRunHungTests(): void
    {
        if (!\function_exists('pcntl_signal')
            || !\function_exists('pcntl_exec')
            || !\function_exists('posix_kill')
            || !\function_exists('posix_setpgid')
        ) {
            throw new SkipTest('The second-signal test requires PCNTL and POSIX functions.');
        }

        $project = $this->writeProject();
        $markerDir = $project->path('markers');
        $root = \dirname(__DIR__, 2);
        $process = PhpSubprocess::start(
            $project->directory,
            [
                \dirname(__DIR__) . '/Fixture/Cli/Signal/ProcessGroupLauncher.php',
                $root . '/bin/greenlight',
                'run',
                '--workers=2',
                '--reporter=plain',
            ],
            ['TMPDIR' => $this->tempDirectory->subdirectory('second-signal/tmp')],
        );
        $this->cleanup->defer($process->terminate(...));
        $this->cleanup->defer(fn() => $this->killWorkers($markerDir));

        $deadline = \microtime(true) + self::DEADLINE_SECONDS;

        while (\microtime(true) < $deadline && \count($this->workerPids($markerDir)) < 2) {
            $process->pump();
            \usleep(5_000);
        }

        $workerPids = $this->workerPids($markerDir);

        if (\count($workerPids) < 2) {
            Fail::because('Timed out while it waited for both hung tests to start.');
        }

        $process->signal(\SIGINT);
        // The first signal starts a drain, which waits for the hung tests.
        \usleep(300_000);
        $process->signal(\SIGINT);
        $result = $process->wait(self::DEADLINE_SECONDS);

        expect($result->exitCode)
            ->because('A forced exit MUST NOT report success')
            ->not()->toBe(0);

        foreach ($workerPids as $pid) {
            expect($this->processExists($pid))
                ->because(\sprintf('Worker process %d MUST NOT outlive a forced exit', $pid))
                ->toBeFalse();
        }
    }

    /** @return list<int> */
    private function workerPids(string $markerDir): array
    {
        $pids = [];

        $files = \glob($markerDir . '/*.pid');

        foreach ($files === false ? [] : $files as $file) {
            $pid = (int) \file_get_contents($file);

            if ($pid > 0) {
                $pids[] = $pid;
            }
        }

        return $pids;
    }

    private function processExists(int $pid): bool
    {
        // A killed worker can stay a zombie until its parent ends. The
        // parent is gone, so init reaps it. Wait for that.
        $deadline = \microtime(true) + 5.0;

        do {
            $state = \trim(Subprocess::run(\dirname(__DIR__, 2), ['ps', '-p', (string) $pid, '-o', 'stat='])->stdout);

            if ($state === '' || \str_starts_with($state, 'Z')) {
                return false;
            }

            \usleep(50_000);
        } while (\microtime(true) < $deadline);

        return true;
    }

    private function killWorkers(string $markerDir): void
    {
        foreach ($this->workerPids($markerDir) as $pid) {
            \posix_kill($pid, \SIGKILL);
        }
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'second-signal');
        $project->writeFile('markers/.gitkeep', '');
        $markerDir = \var_export($project->path('markers'), true);
        $files = [];

        foreach (['Alpha', 'Bravo'] as $name) {
            $file = \sprintf('tests/%sTest.php', $name);
            $project->writeFile($file, <<<PHP
                <?php

                declare(strict_types=1);

                namespace SecondSignalProbe;

                use Greenlight\\Attribute\\Test;

                final class {$name}Test
                {
                    #[Test]
                    public function hangs(): void
                    {
                        \\file_put_contents({$markerDir} . '/{$name}.pid', (string) \\getmypid());

                        while (true) {
                            \\usleep(100_000);
                        }
                    }
                }
                PHP);
            $files[] = $file;
        }

        $project->configureWithTestFiles($files, workers: 2);

        return $project;
    }
}
