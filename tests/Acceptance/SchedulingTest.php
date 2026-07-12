<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

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
            // Cold run: no cache yet. Records durations and proves reuse:
            // two workers cover four classes.
            [$exit, $lines] = $this->run($project);
            Expect::that($exit)->toBe(0)
                ->and(\count($this->spawnedWorkers($lines)))->toBe(2);

            // Warm run: the slow class heads the queue, so whichever worker
            // takes it receives it as its first assignment. Assert per
            // worker rather than on the merged stream: event order between
            // workers is arrival order, and a worker that boots slowly on a
            // loaded machine reports its first start only after the other
            // worker has already started several classes.
            [$exit, $lines] = $this->run($project);
            $firstStarts = $this->firstClassStartedByWorker($lines);

            Expect::that($exit)->toBe(0)
                ->and(\count($this->spawnedWorkers($lines)))->toBe(2)
                ->and(\array_values($firstStarts))->toContain('SchedulingProbe\SlowTest');
        } finally {
            $project->remove();
        }
    }

    /**
     * @return array{int, list<string>}
     */
    private function run(AcceptanceProject $project): array
    {
        return $project->runLines('run', '--workers=2', '--reporter=jsonl');
    }

    /**
     * The first class each worker started, keyed by worker id.
     *
     * @param list<string> $lines
     *
     * @return array<string, string>
     */
    private function firstClassStartedByWorker(array $lines): array
    {
        $firsts = [];

        foreach ($lines as $line) {
            $decoded = \json_decode($line, true);

            if (\is_array($decoded) && ($decoded['event'] ?? null) === 'class-started'
                && \is_array($decoded['data'] ?? null)
                && \is_string($decoded['data']['class'] ?? null)
                && \is_string($decoded['data']['workerId'] ?? null)
                && !isset($firsts[$decoded['data']['workerId']])) {
                $firsts[$decoded['data']['workerId']] = $decoded['data']['class'];
            }
        }

        return $firsts;
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

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create('scheduling');

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
            $project->write(\sprintf('tests/%sTest.php', $name), \sprintf($fast, $name));
        }

        $project->write('tests/SlowTest.php', <<<'PHP'
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
