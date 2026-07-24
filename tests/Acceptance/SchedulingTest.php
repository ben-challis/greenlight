<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;
use Greenlight\Tests\Support\ProcessResult;

/**
 * Demand-driven scheduling through the real CLI.
 *
 * Workers are reused across classes instead of spawning per unit, and once
 * the timing cache knows a slow class it is assigned first on the next run.
 */
final readonly class SchedulingTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function workersAreReusedAndTheSlowClassLeadsOnceKnown(): void
    {
        $project = $this->writeProject();
        // Cold run: no cache yet. Records durations and proves reuse:
        // two workers cover four classes.
        $result = $this->run($project);
        $events = JsonlEvents::from($result);
        Expect::that($result->exitCode)->toBe(0)
            ->and(\count($this->spawnedWorkers($events)))->toBe(2);
        // Warm run: the slow class heads the queue, so whichever worker
        // takes it receives it as its first assignment. Assert per
        // worker rather than on the merged stream: event order between
        // workers is arrival order, and a worker that boots slowly on a
        // loaded machine reports its first start only after the other
        // worker has already started several classes.
        $result = $this->run($project);
        $events = JsonlEvents::from($result);
        $firstStarts = $this->firstClassStartedByWorker($events);
        Expect::that($result->exitCode)->toBe(0)
            ->and(\count($this->spawnedWorkers($events)))->toBe(2)
            ->and(\array_values($firstStarts))->toContain('SchedulingProbe\SlowTest');
    }

    private function run(AcceptanceProject $project): ProcessResult
    {
        return GreenlightCli::run($project->directory, ['run', '--workers=2', '--reporter=jsonl']);
    }

    /**
     * The first class each worker started, keyed by worker id.
     *
     * @param list<Event> $events
     *
     * @return array<string, string>
     */
    private function firstClassStartedByWorker(array $events): array
    {
        $firsts = [];

        foreach ($events as $event) {
            if ($event instanceof TestClassStarted && !isset($firsts[$event->workerId])) {
                $firsts[$event->workerId] = $event->class;
            }
        }

        return $firsts;
    }

    /**
     * @param list<Event> $events
     *
     * @return list<string>
     */
    private function spawnedWorkers(array $events): array
    {
        $workers = [];

        foreach ($events as $event) {
            if ($event instanceof WorkerSpawned) {
                $workers[] = $event->workerId;
            }
        }

        return $workers;
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'scheduling');

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
