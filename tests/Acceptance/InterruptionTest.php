<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\SkipTest;

/**
 * Interrupts a real bin/greenlight run with SIGINT and asserts the graceful
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

        $project = $this->writeProject();
        $tmp = $project . '/tmp';
        \mkdir($tmp, 0o700);

        try {
            $root = \dirname(__DIR__, 2);
            $env = \getenv();
            $env['TMPDIR'] = $tmp;

            $process = \proc_open(
                [\PHP_BINARY, $root . '/bin/greenlight', 'run', '--workers=2', '--reporter=jsonl'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $project,
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

            while (\microtime(true) < $deadline && !\str_contains($stdout, '"test-finished"')) {
                $this->pump($pipes, $stdout, $stderr);
                \usleep(20_000);
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

            $expect = new Expect();

            $expect->that($stdout)->toContain('"test-finished"')
                ->and($exit)->toBe(130)
                ->and($stderr)->toContain('Interrupted');

            foreach ($this->spawnedWorkerPids($stdout) as $pid) {
                \exec(\sprintf('ps -p %d -o pid=', $pid), $alive);
                $expect->that(\trim(\implode('', $alive)))->toBe('');
            }

            $sockets = \glob($tmp . '/greenlight-*/orchestrator.sock');
            $expect->that(\is_array($sockets) ? $sockets : [])->toBe([]);
        } finally {
            $this->removeTree($project);
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

    private function writeProject(): string
    {
        $project = \sys_get_temp_dir() . '/greenlight-interrupt-' . \bin2hex(\random_bytes(6));
        \mkdir($project . '/tests', 0o777, true);

        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace InterruptProbe;

            use Greenlight\Attribute\Test;

            final class %sTest
            {
                #[Test]
                public function one(): void { \usleep(100_000); }

                #[Test]
                public function two(): void { \usleep(100_000); }

                #[Test]
                public function three(): void { \usleep(100_000); }

                #[Test]
                public function four(): void { \usleep(100_000); }

                #[Test]
                public function five(): void { \usleep(100_000); }
            }
            PHP;

        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot'] as $name) {
            \file_put_contents($project . \sprintf('/tests/%sTest.php', $name), \sprintf($template, $name));
        }

        \file_put_contents($project . '/greenlight.php', <<<'PHP'
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

    private function removeTree(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if ($entry->isDir() && !$entry->isLink()) {
                @\rmdir($entry->getPathname());
            } else {
                @\unlink($entry->getPathname());
            }
        }

        @\rmdir($directory);
    }
}
