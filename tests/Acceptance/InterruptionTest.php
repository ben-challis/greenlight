<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\SkipTest;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Interrupts a real bin/greenlight run with SIGINT and asserts the clean
 * shutdown contract: exit code 130, the interrupted marker, no orphaned
 * worker processes, and no leaked orchestrator socket directory. The run
 * gets a private TMPDIR so temp-dir assertions cannot race other tests.
 */
final class InterruptionTest
{
    private const float DEADLINE_SECONDS = 30.0;

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
        $tmp = $project->path('tmp');
        \mkdir($tmp, 0o700);
        $markerDir = $project->path('markers');

        try {
            $root = \dirname(__DIR__, 2);
            $env = \getenv();
            $env['TMPDIR'] = $tmp;

            $process = \proc_open(
                [\PHP_BINARY, $root . '/bin/greenlight', 'run', '--workers=2', '--reporter=jsonl'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $project->directory,
                $env,
            );

            if (!\is_resource($process)) {
                throw new \RuntimeException('Could not start bin/greenlight.');
            }

            \fclose($pipes[0]);
            \stream_set_blocking($pipes[1], false);
            \stream_set_blocking($pipes[2], false);

            $stdout = '';
            $stderr = '';
            $deadline = \microtime(true) + self::DEADLINE_SECONDS;

            // A marker file written straight to disk at the top of the
            // first test method fires as soon as any test has started,
            // with none of the block-buffering delay a "test-finished"
            // line in the piped stdout would carry: under CPU pressure the
            // whole run could otherwise finish before that line is ever
            // observed, sending SIGINT after there is nothing left to
            // interrupt.
            while (\microtime(true) < $deadline && \glob($markerDir . '/*.started') === []) {
                $this->pump($pipes, $stdout, $stderr);
                \usleep(5_000);
            }

            if (\glob($markerDir . '/*.started') === []) {
                throw new \RuntimeException(\sprintf(
                    'Timed out after %.1fs waiting for a fixture test to start.',
                    self::DEADLINE_SECONDS,
                ));
            }

            $status = \proc_get_status($process);
            \exec('kill -INT ' . $status['pid']);

            $exit = null;

            while (\microtime(true) < $deadline) {
                $this->pump($pipes, $stdout, $stderr);
                $status = \proc_get_status($process);

                if (!$status['running']) {
                    $exit = $status['exitcode'];

                    break;
                }

                \usleep(20_000);
            }

            $this->pump($pipes, $stdout, $stderr);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            \proc_close($process);


            Expect::that($stdout)->toContain('"test-finished"')
                ->and($exit)->toBe(130)
                ->and($stderr)->toContain('Interrupted');

            foreach ($this->spawnedWorkerPids($stdout) as $pid) {
                \exec(\sprintf('ps -p %d -o pid=', $pid), $alive);
                Expect::that(\trim(\implode('', $alive)))->toBe('');
            }

            $sockets = \glob($tmp . '/greenlight-*/orchestrator.sock');
            Expect::that(\is_array($sockets) ? $sockets : [])->toBe([]);
        } finally {
            $project->remove();
        }
    }

    /**
     * @param array<int, resource> $pipes
     */
    private function pump(array $pipes, string &$stdout, string &$stderr): void
    {
        $stdout .= (string) @\fread($pipes[1], 65536);
        $stderr .= (string) @\fread($pipes[2], 65536);
    }

    /**
     * @return list<int>
     */
    private function spawnedWorkerPids(string $stdout): array
    {
        $pids = [];

        foreach (\explode("\n", $stdout) as $line) {
            $decoded = \json_decode($line, true);

            if (\is_array($decoded) && ($decoded['event'] ?? null) === 'worker-spawned'
                && \is_array($decoded['data'] ?? null) && \is_int($decoded['data']['pid'] ?? null)) {
                $pids[] = $decoded['data']['pid'];
            }
        }

        return $pids;
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create('interrupt');
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

        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot'] as $name) {
            $project->write(\sprintf('tests/%sTest.php', $name), \sprintf(
                $template,
                $name,
                \var_export($markerDir, true),
                $name,
            ));
        }

        $project->write('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            foreach (\glob(__DIR__ . '/tests/*Test.php') ?: [] as $file) {
                require_once $file;
            }

            return GreenlightConfig::create()->paths([__DIR__ . '/tests']);
            PHP);

        return $project;
    }
}
