<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;
use Greenlight\Tests\Support\ProcessResult;

use function Greenlight\expect;

final readonly class SelectionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function filterSelectsByMethod(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=alwaysPasses');

        expect($result->exitCode)->because('filter selects by method')->toBe(0);
        expect($this->sortedFinishedTestIds($result))->because('filter selects by method')->toBe([
            'SelectionProbe\SelectionProbeTest::alwaysPasses',
        ]);
    }

    #[Test]
    public function filterSelectsByClass(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=SelectionProbeTest');

        expect($result->exitCode)->because('filter selects by class')->toBe(1);
        expect($this->sortedFinishedTestIds($result))->because('filter selects by class')->toBe([
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

        expect($result->exitCode)->because('filter selects with a wildcard')->toBe(1);
        expect($this->sortedFinishedTestIds($result))->because('filter selects with a wildcard')->toBe([
            'SelectionProbe\SelectionProbeTest::breaksSometimes',
        ]);
    }

    #[Test]
    public function filterReportsNoTestsWhenNothingMatches(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=nothingMatchesThis');

        expect($result->exitCode)->because('filter reports no tests when nothing matches')->toBe(1);
        expect($result->output())->because('filter reports no tests when nothing matches')->toContain('Greenlight found no tests');
    }

    #[Test]
    public function testIdSelectsOnlyAnExactId(): void
    {
        $project = $this->writeProject();
        $result = $this->run(
            $project,
            '--test-id=SelectionProbe\SelectionProbeTest::alsoPasses',
        );

        expect($result->exitCode)->because('test ID selects only an exact ID')->toBe(0);
        expect($this->sortedFinishedTestIds($result))->because('test ID selects only an exact ID')->toBe([
            'SelectionProbe\SelectionProbeTest::alsoPasses',
        ]);

        $result = $this->run(
            $project,
            '--test-id=SelectionProbe\SelectionProbeTest::also',
        );

        expect($result->exitCode)->because('test ID selects only an exact ID')->toBe(1);
        expect($result->output())->because('test ID selects only an exact ID')->toContain('did not find the requested exact test ID');
    }

    #[Test]
    public function testIdFileSelectsDeduplicatedExactIdsAndRejectsStaleIds(): void
    {
        $project = $this->writeProject();
        $project->writeFile(
            'exact-tests.txt',
            "\nSelectionProbe\\SelectionProbeTest::alsoPasses\nSelectionProbe\\SelectionProbeTest::alsoPasses\n",
        );

        $result = $this->run($project, '--test-id-file=exact-tests.txt');

        expect($result->exitCode)->because('the exact test ID file selects one known test')->toBe(0);
        expect($this->sortedFinishedTestIds($result))->toBe([
            'SelectionProbe\SelectionProbeTest::alsoPasses',
        ]);

        $project->writeFile('exact-tests.txt', "SelectionProbe\\SelectionProbeTest::removed\n");
        $result = $this->run($project, '--test-id-file=exact-tests.txt');

        expect($result->exitCode)->because('a stale exact test ID MUST fail discovery')->toBe(1);
        expect($result->output())->toContain('did not find the requested exact test ID')
            ->toContain('SelectionProbe\SelectionProbeTest::removed');
    }

    #[Test]
    public function groupSelectsMatchingTests(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--group=fast');

        expect($result->exitCode)->because('group selects matching tests')->toBe(0);
        expect($this->sortedFinishedTestIds($result))->because('group selects matching tests')->toBe([
            'SelectionProbe\GroupedProbeTest::fastOne',
        ]);
    }

    #[Test]
    public function excludeGroupRemovesGroupedTestsFromARun(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--exclude-group=slow');

        expect($result->exitCode)->because('exclude group removes grouped tests from a run')->toBe(1);
        expect($this->sortedFinishedTestIds($result))->because('exclude group removes grouped tests from a run')->toBe([
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

        expect($result->exitCode)->because('exclude method with a wildcard removes matching methods')->toBe(1);
        expect($this->sortedFinishedTestIds($result))->because('exclude method with a wildcard removes matching methods')->toBe([
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

        expect($result->exitCode)->because('exclude wins over an include filter')->toBe(1);
        expect($result->output())->because('exclude wins over an include filter')->toContain('Greenlight found no tests');
    }

    #[Test]
    public function excludedGroupWinsOverIncludedGroups(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--group=fast', '--group=slow', '--exclude-group=slow');

        expect($result->exitCode)->because('excluded group wins over included groups')->toBe(0);
        expect($this->sortedFinishedTestIds($result))->because('excluded group wins over included groups')->toBe([
            'SelectionProbe\GroupedProbeTest::fastOne',
        ]);
    }

    #[Test]
    public function failedRerunsExactlyThePreviousFailures(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--failed');
        expect($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(64);
        expect($result->output())->because('failed reruns exactly the previous failures')->toContain('previous run');

        $result = $this->run($project);
        expect($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(1);
        expect($this->sortedFinishedTestIds($result))->because('failed reruns exactly the previous failures')->toBe([
            'SelectionProbe\GroupedProbeTest::fastOne',
            'SelectionProbe\GroupedProbeTest::slowOne',
            'SelectionProbe\SelectionProbeTest::alsoPasses',
            'SelectionProbe\SelectionProbeTest::alwaysPasses',
            'SelectionProbe\SelectionProbeTest::breaksSometimes',
        ]);

        $result = $this->run($project, '--failed');
        expect($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(1);
        expect($this->sortedFinishedTestIds($result))->because('failed reruns exactly the previous failures')->toBe([
            'SelectionProbe\SelectionProbeTest::breaksSometimes',
        ]);

        $result = $this->run($project, '--filter=alwaysPasses');
        expect($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(0);
        expect($this->sortedFinishedTestIds($result))->because('failed reruns exactly the previous failures')->toBe([
            'SelectionProbe\SelectionProbeTest::alwaysPasses',
        ]);

        $result = $this->run($project, '--failed');
        expect($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(0);
        expect($result->output())->because('failed reruns exactly the previous failures')->toContain('No tests failed');
        expect($result->stdout)->toBe('');
        expect($result->stderr)->toContain('No tests failed');
    }

    #[Test]
    public function failedKeepsTheEmptySelectionNoticeOnStandardOutputForPlainReports(): void
    {
        $project = $this->writeProject();
        $this->run($project, '--filter=alwaysPasses');

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--failed']);

        expect($result->exitCode)->toBe(0);
        expect($result->stdout)->toContain('No tests failed');
        expect($result->stderr)->toBe('');
    }

    #[Test]
    public function failedKeepsTheEmptySelectionNoticeOnStandardOutputWhenJsonlUsesAFile(): void
    {
        $project = $this->writeProject();
        $this->run($project, '--filter=alwaysPasses');

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=jsonl=events.jsonl', '--failed']);

        expect($result->exitCode)->toBe(0);
        expect($result->stdout)->toContain('No tests failed');
        expect($result->stderr)->toBe('');
        expect(\file_get_contents($project->directory . '/events.jsonl'))->toBe('');
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
        expect($result->exitCode)->because('unpersistable run state warns without failing the run')->toBe(0);
        expect($this->sortedFinishedTestIds($result))->because('unpersistable run state warns without failing the run')->toBe([
            'SelectionProbe\SelectionProbeTest::alwaysPasses',
        ]);
        expect($result->output())->because('unpersistable run state warns without failing the run')->toContain('Greenlight did not save run state');
    }

    private function run(AcceptanceProject $project, string ...$flags): ProcessResult
    {
        return GreenlightCli::run($project->directory, \array_values(['run', '--reporter=jsonl', ...$flags]));
    }

    /**
     * @return list<string>
     */
    private function sortedFinishedTestIds(ProcessResult $result): array
    {
        $testIds = JsonlEvents::finishedTestIds($result);

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
