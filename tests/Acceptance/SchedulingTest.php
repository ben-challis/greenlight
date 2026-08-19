<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Cli\RunState;
use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\TestClassStarted;
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
    public function workersAreReusedAndTheCachedSlowClassLeads(): void
    {
        $project = $this->writeProject();
        Expect::that(RunState::forWorkingDirectory($project->directory)->record(
            [],
            ['SchedulingProbe\SlowTest' => 1.0],
        ))->because('the timing cache is available to the acceptance run')->toBeTrue();

        $result = $this->run($project);
        $events = JsonlEvents::from($result);
        $firstStarts = $this->firstClassStartedByWorker($events);
        $spawnedWorkers = JsonlEvents::spawnedWorkerIds($events);
        $startedClasses = $this->startedClasses($events);
        \sort($startedClasses);

        Expect::that($result->exitCode)->because('the scheduled run succeeds')->toBe(0);
        Expect::that($spawnedWorkers)->because('the run starts two workers')->toHaveCount(2);
        Expect::that($startedClasses)->because('the workers execute all four classes')->toBe([
            'SchedulingProbe\AlphaTest',
            'SchedulingProbe\BravoTest',
            'SchedulingProbe\CharlieTest',
            'SchedulingProbe\SlowTest',
        ]);
        Expect::that(\array_values($firstStarts))
            ->because('the cached slow class is one of the first assignments')
            ->toContain('SchedulingProbe\SlowTest');
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
    private function startedClasses(array $events): array
    {
        $classes = [];

        foreach ($events as $event) {
            if ($event instanceof TestClassStarted) {
                $classes[] = $event->class;
            }
        }

        return $classes;
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
            $project->writeFile(\sprintf('tests/%sTest.php', $name), \sprintf($fast, $name));
        }

        $project->writeFile('tests/SlowTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SchedulingProbe;

            use Greenlight\Attribute\Test;

            final class SlowTest
            {
                #[Test]
                public function quick(): void {}
            }
            PHP);

        $project->writeFile('greenlight.php', <<<'PHP'
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
