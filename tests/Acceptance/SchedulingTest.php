<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Cli\RunState;
use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;
use Greenlight\Tests\Support\ProcessResult;

final readonly class SchedulingTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

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

    #[Test]
    public function classWallDurationMakesOverheadHeavyClassLeadTheNextRun(): void
    {
        $project = $this->writeOverheadProject();

        $first = $this->run($project, workers: 1);
        $firstClasses = $this->startedClasses(JsonlEvents::from($first));
        $cached = RunState::forWorkingDirectory($project->directory)->classSeconds();
        $second = $this->run($project, workers: 1);
        $secondClasses = $this->startedClasses(JsonlEvents::from($second));
        $firstOutput = $first->output();
        $secondOutput = $second->output();

        Expect::that($first->exitCode)
            ->because($firstOutput === '' ? 'The first scheduling run returned no output.' : $firstOutput)
            ->toBe(0);
        Expect::that($firstClasses)
            ->because('the first run MUST use discovery order without timing data')
            ->toBe([
                'SchedulingOverheadProbe\AlphaTest',
                'SchedulingOverheadProbe\SlowTest',
            ]);
        Expect::that($cached['SchedulingOverheadProbe\SlowTest'] ?? 0.0)
            ->because('the timing cache MUST include plugin overhead after the test result duration')
            ->toBeGreaterThan($cached['SchedulingOverheadProbe\AlphaTest'] ?? \PHP_FLOAT_MAX);
        Expect::that($second->exitCode)
            ->because($secondOutput === '' ? 'The second scheduling run returned no output.' : $secondOutput)
            ->toBe(0);
        Expect::that($secondClasses)
            ->because('the next run MUST schedule the overhead-heavy class first')
            ->toBe([
                'SchedulingOverheadProbe\SlowTest',
                'SchedulingOverheadProbe\AlphaTest',
            ]);
    }

    private function run(AcceptanceProject $project, int $workers = 2): ProcessResult
    {
        return GreenlightCli::run($project->directory, ['run', '--workers=' . $workers, '--reporter=jsonl']);
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

    private function writeOverheadProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'scheduling-overhead');
        $project->writeFile('OverheadPlugin.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SchedulingOverheadProbe;

            use Greenlight\Core\Result\TestResult;
            use Greenlight\Plugin\TestContext;
            use Greenlight\Plugin\TestLifecycleSubscriber;

            final class OverheadPlugin implements TestLifecycleSubscriber
            {
                public function beforeTest(TestContext $context): void {}

                public function afterTest(TestContext $context, TestResult $result): TestResult
                {
                    if ($context->id->class === SlowTest::class) {
                        \usleep(300_000);
                    }

                    return $result;
                }
            }
            PHP);
        $project->writeFile('tests/AlphaTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SchedulingOverheadProbe;

            use Greenlight\Attribute\Test;

            final class AlphaTest
            {
                #[Test]
                public function slowerTestBody(): void
                {
                    \usleep(20_000);
                }
            }
            PHP);
        $project->writeFile('tests/SlowTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SchedulingOverheadProbe;

            use Greenlight\Attribute\Test;

            final class SlowTest
            {
                #[Test]
                public function quickTestBody(): void {}
            }
            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use SchedulingOverheadProbe\OverheadPlugin;

            require_once __DIR__ . '/OverheadPlugin.php';

            foreach (\glob(__DIR__ . '/tests/*Test.php') ?: [] as $file) {
                require_once $file;
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->plugins(new OverheadPlugin());
            PHP);

        return $project;
    }
}
