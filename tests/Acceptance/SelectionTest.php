<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class SelectionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function filterSelectsByMethodClassAndWildcard(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=alwaysPasses');
        Expect::that($result->exitCode)->because('filter selects by method class and wildcard')->toBe(0)->and($result->output())->toContain('1 test, 1 passed');
        $result = $this->run($project, '--filter=SelectionProbeTest');
        Expect::that($result->output())->because('filter selects by method class and wildcard')->toContain('3 tests,');
        $result = $this->run($project, '--filter=*::breaks?ometimes');
        Expect::that($result->exitCode)->because('filter selects by method class and wildcard')->toBe(1)->and($result->output())->toContain('1 test, 0 passed, 1 errored');
        $result = $this->run($project, '--filter=nothingMatchesThis');
        Expect::that($result->exitCode)->because('filter selects by method class and wildcard')->toBe(1)->and($result->output())->toContain('No tests found');
    }

    #[Test]
    public function testIdSelectsOnlyAnExactId(): void
    {
        $project = $this->writeProject();
        $result = $this->run(
            $project,
            '--test-id=SelectionProbe\SelectionProbeTest::alsoPasses',
        );

        Expect::that($result->exitCode)->because('test ID selects only an exact ID')->toBe(0)
            ->and($result->output())->toContain('1 test, 1 passed')
            ->not()->toContain('alwaysPasses');

        $result = $this->run(
            $project,
            '--test-id=SelectionProbe\SelectionProbeTest::also',
        );

        Expect::that($result->exitCode)->because('test ID selects only an exact ID')->toBe(1)
            ->and($result->output())->toContain('No tests found');
    }

    #[Test]
    public function excludeGroupRemovesGroupedTestsFromARun(): void
    {
        $project = $this->writeProject();
        // The complete project has five tests. Exclusion of the slow group
        // removes one test.
        $result = $this->run($project, '--exclude-group=slow');
        Expect::that($result->exitCode)->because('exclude group removes grouped tests from a run')->toBe(1)->and($result->output())->toContain('4 tests,');
        $result = $this->run($project, '--group=fast', '--exclude-group=slow');
        Expect::that($result->exitCode)->because('exclude group removes grouped tests from a run')->toBe(0)->and($result->output())->toContain('1 test, 1 passed');
    }

    #[Test]
    public function excludeMethodWithAWildcardRemovesMatchingMethods(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--exclude-method=*Passes');
        Expect::that($result->exitCode)->because('exclude method with a wildcard removes matching methods')->toBe(1)->and($result->output())->toContain('3 tests,');
    }

    #[Test]
    public function excludeWinsOverAnIncludeFilter(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=alwaysPasses', '--exclude-method=alwaysPasses');
        Expect::that($result->exitCode)->because('exclude wins over an include filter')->toBe(1)->and($result->output())->toContain('No tests found');
        $result = $this->run($project, '--group=fast', '--group=slow', '--exclude-group=slow');
        Expect::that($result->exitCode)->because('exclude wins over an include filter')->toBe(0)->and($result->output())->toContain('1 test, 1 passed');
    }

    #[Test]
    public function failedRerunsExactlyThePreviousFailures(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--failed');
        Expect::that($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(64)->and($result->output())->toContain('previous run');
        $result = $this->run($project);
        Expect::that($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(1);
        $result = $this->run($project, '--failed');
        Expect::that($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(1)
            ->and($result->output())->toContain('1 test, 0 passed, 1 errored')
            ->toContain('breaksSometimes');
        $result = $this->run($project, '--filter=alwaysPasses');
        Expect::that($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(0);
        $result = $this->run($project, '--failed');
        Expect::that($result->exitCode)->because('failed reruns exactly the previous failures')->toBe(0)->and($result->output())->toContain('Nothing failed');
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
            ['run', '--reporter=plain', '--filter=alwaysPasses'],
            ['TMPDIR' => $project->directory . '/not-a-directory'],
        );
        Expect::that($result->exitCode)->because('unpersistable run state warns without failing the run')->toBe(0)
            ->and($result->output())->toContain('1 test, 1 passed')
            ->toContain('Run state was not saved');
    }

    private function run(AcceptanceProject $project, string ...$flags): ProcessResult
    {
        return GreenlightCli::run($project->directory, \array_values(['run', '--reporter=plain', ...$flags]));
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
