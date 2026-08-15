<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;
use Greenlight\Tests\Support\ProcessResult;

final readonly class SelectionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function filterSelectsByMethod(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=alwaysPasses');

        Expect::that($result->exitCode)->because('filter selects by method')->toBe(0);
        Expect::that($this->finishedTestIds($result))->because('filter selects by method')->toBe([
            'SelectionProbe\SelectionProbeTest::alwaysPasses',
        ]);
    }

    #[Test]
    public function filterSelectsByClass(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=SelectionProbeTest');

        Expect::that($result->exitCode)->because('filter selects by class')->toBe(1);
        Expect::that($this->finishedTestIds($result))->because('filter selects by class')->toBe([
            'SelectionProbe\SelectionProbeTest::alsoPasses',
            'SelectionProbe\SelectionProbeTest::alwaysPasses',
            'SelectionProbe\SelectionProbeTest::breaksSometimes',
        ]);
    }

    #[Test]
    public function filterSelectsWithAWildcard(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=*::breaks?ometimes');

        Expect::that($result->exitCode)->because('filter selects with a wildcard')->toBe(1);
        Expect::that($this->finishedTestIds($result))->because('filter selects with a wildcard')->toBe([
            'SelectionProbe\SelectionProbeTest::breaksSometimes',
        ]);
    }

    #[Test]
    public function filterReportsNoTestsWhenNothingMatches(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=nothingMatchesThis');

        Expect::that($result->exitCode)->because('filter reports no tests when nothing matches')->toBe(1);
        Expect::that($result->output())->because('filter reports no tests when nothing matches')->toContain('Greenlight found no tests');
    }

    #[Test]
    public function testIdSelectsOnlyAnExactId(): void
    {
        $project = $this->writeProject();
        $result = $this->run(
            $project,
            '--test-id=SelectionProbe\SelectionProbeTest::alsoPasses',
        );

        Expect::that($result->exitCode)->because('test ID selects only an exact ID')->toBe(0);
        Expect::that($this->finishedTestIds($result))->because('test ID selects only an exact ID')->toBe([
            'SelectionProbe\SelectionProbeTest::alsoPasses',
        ]);

        $result = $this->run(
            $project,
            '--test-id=SelectionProbe\SelectionProbeTest::also',
        );

        Expect::that($result->exitCode)->because('test ID selects only an exact ID')->toBe(1);
        Expect::that($result->output())->because('test ID selects only an exact ID')->toContain('Greenlight found no tests');
    }

    #[Test]
    public function groupSelectsMatchingTests(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--group=fast');

        Expect::that($result->exitCode)->because('group selects matching tests')->toBe(0);
        Expect::that($this->finishedTestIds($result))->because('group selects matching tests')->toBe([
            'SelectionProbe\GroupedProbeTest::fastOne',
        ]);
    }

    #[Test]
    public function excludeGroupRemovesGroupedTestsFromARun(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--exclude-group=slow');

        Expect::that($result->exitCode)->because('exclude group removes grouped tests from a run')->toBe(1);
        Expect::that($this->finishedTestIds($result))->because('exclude group removes grouped tests from a run')->toBe([
            'SelectionProbe\GroupedProbeTest::fastOne',
            'SelectionProbe\SelectionProbeTest::alsoPasses',
            'SelectionProbe\SelectionProbeTest::alwaysPasses',
            'SelectionProbe\SelectionProbeTest::breaksSometimes',
        ]);
    }

    #[Test]
    public function excludeMethodWithAWildcardRemovesMatchingMethods(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--exclude-method=*Passes');

        Expect::that($result->exitCode)->because('exclude method with a wildcard removes matching methods')->toBe(1);
        Expect::that($this->finishedTestIds($result))->because('exclude method with a wildcard removes matching methods')->toBe([
            'SelectionProbe\GroupedProbeTest::fastOne',
            'SelectionProbe\GroupedProbeTest::slowOne',
            'SelectionProbe\SelectionProbeTest::breaksSometimes',
        ]);
    }

    #[Test]
    public function excludeWinsOverAnIncludeFilter(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=alwaysPasses', '--exclude-method=alwaysPasses');

        Expect::that($result->exitCode)->because('exclude wins over an include filter')->toBe(1);
        Expect::that($result->output())->because('exclude wins over an include filter')->toContain('Greenlight found no tests');
    }

    #[Test]
    public function excludedGroupWinsOverIncludedGroups(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--group=fast', '--group=slow', '--exclude-group=slow');

        Expect::that($result->exitCode)->because('excluded group wins over included groups')->toBe(0);
        Expect::that($this->finishedTestIds($result))->because('excluded group wins over included groups')->toBe([
            'SelectionProbe\GroupedProbeTest::fastOne',
        ]);
    }

    #[Test]
    public function failedRerunsExactlyThePreviousFailures(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--failed');
        Expect::that($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(64);
        Expect::that($result->output())->because('failed reruns exactly the previous failures')->toContain('previous run');

        $result = $this->run($project);
        Expect::that($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(1);
        Expect::that($this->finishedTestIds($result))->because('failed reruns exactly the previous failures')->toBe([
            'SelectionProbe\GroupedProbeTest::fastOne',
            'SelectionProbe\GroupedProbeTest::slowOne',
            'SelectionProbe\SelectionProbeTest::alsoPasses',
            'SelectionProbe\SelectionProbeTest::alwaysPasses',
            'SelectionProbe\SelectionProbeTest::breaksSometimes',
        ]);

        $result = $this->run($project, '--failed');
        Expect::that($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(1);
        Expect::that($this->finishedTestIds($result))->because('failed reruns exactly the previous failures')->toBe([
            'SelectionProbe\SelectionProbeTest::breaksSometimes',
        ]);

        $result = $this->run($project, '--filter=alwaysPasses');
        Expect::that($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(0);
        Expect::that($this->finishedTestIds($result))->because('failed reruns exactly the previous failures')->toBe([
            'SelectionProbe\SelectionProbeTest::alwaysPasses',
        ]);

        $result = $this->run($project, '--failed');
        Expect::that($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(0);
        Expect::that($result->output())->because('failed reruns exactly the previous failures')->toContain('No tests failed');
    }

    #[Test]
    public function unpersistableRunStateWarnsWithoutFailingTheRun(): void
    {
        $project = $this->writeProject();

        // TMPDIR identifies a regular file, not a missing directory.
        // Observability agents can create a missing TMPDIR for their sockets.
        // No process can create an entry under a file. Thus, the state write
        // fails on each platform.
        $project->writeFile('not-a-directory', '');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--reporter=jsonl', '--filter=alwaysPasses'],
            ['TMPDIR' => $project->directory . '/not-a-directory'],
        );
        Expect::that($result->exitCode)->because('unpersistable run state warns without failing the run')->toBe(0);
        Expect::that($this->finishedTestIds($result))->because('unpersistable run state warns without failing the run')->toBe([
            'SelectionProbe\SelectionProbeTest::alwaysPasses',
        ]);
        Expect::that($result->output())->because('unpersistable run state warns without failing the run')->toContain('Greenlight did not save run state');
    }

    private function run(AcceptanceProject $project, string ...$flags): ProcessResult
    {
        return GreenlightCli::run($project->directory, \array_values(['run', '--reporter=jsonl', ...$flags]));
    }

    /**
     * @return list<string>
     */
    private function finishedTestIds(ProcessResult $result): array
    {
        $testIds = [];

        foreach (JsonlEvents::from($result) as $event) {
            if ($event instanceof TestFinished) {
                $testIds[] = (string) $event->result->id;
            }
        }

        \sort($testIds);

        return $testIds;
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'selection');

        $project->writeFile('tests/SelectionProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SelectionProbe;

            use Greenlight\Attribute\Test;

            final class SelectionProbeTest
            {
                #[Test]
                public function alwaysPasses(): void {}

                #[Test]
                public function alsoPasses(): void {}

                #[Test]
                public function breaksSometimes(): never
                {
                    throw new \RuntimeException('intentional selection failure');
                }
            }
            PHP);

        $project->writeFile('tests/GroupedProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SelectionProbe;

            use Greenlight\Attribute\Group;
            use Greenlight\Attribute\Test;

            final class GroupedProbeTest
            {
                #[Test]
                #[Group('fast')]
                public function fastOne(): void {}

                #[Test]
                #[Group('slow')]
                public function slowOne(): void {}
            }
            PHP);

        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/SelectionProbeTest.php';
            require_once __DIR__ . '/tests/GroupedProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1);
            PHP);

        return $project;
    }
}
