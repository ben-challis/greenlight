<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * Demand-driven scheduling through the real CLI.
 *
 * Workers are reused across classes instead of spawning per unit, and once
 * the timing cache knows a slow class it is assigned first on the next run.
 */
final class SchedulingTest
{
    #[Test]
    public function workersAreReusedAndTheSlowClassLeadsOnceKnown(): void
    {
        $project = $this->writeProject();

        try {
            $expect = new Expect();

            // Cold run: no cache yet. Records durations and proves reuse:
            // two workers cover four classes.
            [$exit, $lines] = $this->run($project);
            $expect->that($exit)->toBe(0)
                ->and(\count($this->spawnedWorkers($lines)))->toBe(2);

            // Warm run: the slow class is dequeued first, so it must be
            // among the first two class starts (one per worker; exact stream
            // order between workers is arrival order and not deterministic).
            [$exit, $lines] = $this->run($project);
            $started = $this->classStartOrder($lines);

            $expect->that($exit)->toBe(0)
                ->and(\count($this->spawnedWorkers($lines)))->toBe(2)
                ->and(\array_slice($started, 0, 2))->toContain('SchedulingProbe\SlowTest');
        } finally {
            $this->removeTree($project);
        }
    }

    /**
     * @return array{int, list<string>}
     */
    private function run(string $project): array
    {
        $root = \dirname(__DIR__, 2);
        $command = \sprintf(
            'cd %s && %s %s run --workers=2 --reporter=jsonl 2>&1',
            \escapeshellarg($project),
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($root . '/bin/greenlight'),
        );
        \exec($command, $output, $exit);

        return [$exit, $output];
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function classStartOrder(array $lines): array
    {
        $classes = [];

        foreach ($lines as $line) {
            $decoded = \json_decode($line, true);

            if (\is_array($decoded) && ($decoded['event'] ?? null) === 'class-started'
                && \is_array($decoded['data'] ?? null) && \is_string($decoded['data']['class'] ?? null)) {
                $classes[] = $decoded['data']['class'];
            }
        }

        return $classes;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function spawnedWorkers(array $lines): array
    {
        $workers = [];

        foreach ($lines as $line) {
            $decoded = \json_decode($line, true);

            if (\is_array($decoded) && ($decoded['event'] ?? null) === 'worker-spawned'
                && \is_array($decoded['data'] ?? null) && \is_string($decoded['data']['workerId'] ?? null)) {
                $workers[] = $decoded['data']['workerId'];
            }
        }

        return $workers;
    }

    private function writeProject(): string
    {
        $project = \sys_get_temp_dir() . '/greenlight-scheduling-' . \bin2hex(\random_bytes(6));
        \mkdir($project . '/tests', 0o777, true);

        $fast = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SchedulingProbe;

            use Greenlight\Attribute\Test;

            final class %sTest
            {
                #[Test]
                public function quick(): void {}
            }
            PHP;

        foreach (['Alpha', 'Bravo', 'Charlie'] as $name) {
            \file_put_contents($project . \sprintf('/tests/%sTest.php', $name), \sprintf($fast, $name));
        }

        \file_put_contents($project . '/tests/SlowTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SchedulingProbe;

            use Greenlight\Attribute\Test;

            final class SlowTest
            {
                #[Test]
                public function takesAWhile(): void
                {
                    \usleep(150_000);
                }
            }
            PHP);

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
        $files = \glob($directory . '/tests/*');

        foreach (\is_array($files) ? $files : [] as $file) {
            @\unlink($file);
        }

        @\unlink($directory . '/greenlight.php');
        @\rmdir($directory . '/tests');
        @\rmdir($directory);
    }
}
