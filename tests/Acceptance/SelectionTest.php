<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

/**
 * Drives --filter and --failed through the real CLI against a throwaway
 * project in a unique temp directory, so the run state keyed by that
 * directory cannot collide with other tests.
 */
final readonly class SelectionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function filterSelectsByMethodClassAndWildcard(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=alwaysPasses');
        Expect::that($result->exitCode)->toBe(0)->and($result->output())->toContain('1 test, 1 passed');
        $result = $this->run($project, '--filter=SelectionProbeTest');
        Expect::that($result->output())->toContain('3 tests,');
        $result = $this->run($project, '--filter=*::breaks?ometimes');
        Expect::that($result->exitCode)->toBe(1)->and($result->output())->toContain('1 test, 0 passed, 1 errored');
        $result = $this->run($project, '--filter=nothingMatchesThis');
        Expect::that($result->exitCode)->toBe(1)->and($result->output())->toContain('No tests found');
    }

    #[Test]
    public function excludeGroupRemovesGroupedTestsFromARun(): void
    {
        $project = $this->writeProject();
        // The full project is 5 tests; excluding the slow group drops one.
        $result = $this->run($project, '--exclude-group=slow');
        Expect::that($result->exitCode)->toBe(1)->and($result->output())->toContain('4 tests,');
        $result = $this->run($project, '--group=fast', '--exclude-group=slow');
        Expect::that($result->exitCode)->toBe(0)->and($result->output())->toContain('1 test, 1 passed');
    }

    #[Test]
    public function excludeMethodWithAWildcardRemovesMatchingMethods(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--exclude-method=*Passes');
        Expect::that($result->exitCode)->toBe(1)->and($result->output())->toContain('3 tests,');
    }

    #[Test]
    public function excludeWinsOverAnIncludeFilter(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=alwaysPasses', '--exclude-method=alwaysPasses');
        Expect::that($result->exitCode)->toBe(1)->and($result->output())->toContain('No tests found');
        $result = $this->run($project, '--group=fast', '--group=slow', '--exclude-group=slow');
        Expect::that($result->exitCode)->toBe(0)->and($result->output())->toContain('1 test, 1 passed');
    }

    #[Test]
    public function failedRerunsExactlyThePreviousFailures(): void
    {
        $project = $this->writeProject();
        // --failed before any run is a usage error.
        $result = $this->run($project, '--failed');
        Expect::that($result->exitCode)->toBe(64)->and($result->output())->toContain('previous run');
        // A full run records one failure.
        $result = $this->run($project);
        Expect::that($result->exitCode)->toBe(1);
        // --failed re-runs exactly that one test.
        $result = $this->run($project, '--failed');
        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('1 test, 0 passed, 1 errored')
            ->toContain('breaksSometimes');
        // A run where everything passes empties the state.
        $result = $this->run($project, '--filter=alwaysPasses');
        Expect::that($result->exitCode)->toBe(0);
        $result = $this->run($project, '--failed');
        Expect::that($result->exitCode)->toBe(0)->and($result->output())->toContain('Nothing failed');
    }

    #[Test]
    public function unpersistableRunStateWarnsWithoutFailingTheRun(): void
    {
        $project = $this->writeProject();

        // TMPDIR points at a regular file rather than a missing directory:
        // observability agents (ddtrace) create a missing TMPDIR for their
        // sockets, but nothing can create entries under a file, so the state
        // write fails on every platform.
        $project->write('not-a-directory', '');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--reporter=plain', '--filter=alwaysPasses'],
            ['TMPDIR' => $project->directory . '/not-a-directory'],
        );
        Expect::that($result->exitCode)->toBe(0)
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

        $project->write('tests/SelectionProbeTest.php', <<<'PHP'
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

        $project->write('tests/GroupedProbeTest.php', <<<'PHP'
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

        $project->write('greenlight.php', <<<'PHP'
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
