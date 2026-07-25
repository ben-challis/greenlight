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
        // Assert the warm run per worker. The merged stream uses arrival
        // order, so a slow worker may report its first class after another
        // worker has started several.
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
     * @param list<Event> $events
     *
     * @return array<string, string> first class started by each worker id
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
