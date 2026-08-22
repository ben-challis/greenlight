<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class WatchModeTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function reRunsOnFileChangesAndQuitsOnQ(): void
    {
        $project = $this->writeProject();
        $watchedFile = $project->path('tests/WatchProbeTest.php');
        $original = \file_get_contents($watchedFile);

        if ($original === false) {
            throw new \RuntimeException('Could not read the watched fixture.');
        }

        $process = GreenlightCli::start($project->directory, ['run', '--watch', '--reporter=plain']);
        $this->cleanup->defer($process->terminate(...));

        $output = $process->readStdoutUntil('Waiting for changes', 20.0);
        Expect::that($output)->toContain('1 test, 1 passed');

        // Append a comment to make a synthetic change. The size changes, but
        // the modification time can remain equal.
        $project->writeFile('tests/WatchProbeTest.php', $original . "// touched\n");

        $output = $process->readStdoutUntil('Waiting for changes', 20.0);
        Expect::that($output)->toContain('Detected changes')
            ->toContain('1 test, 1 passed');

        $process->write('q');
        $result = $process->wait(10.0);
        $provisioned = \file($project->path('markers/provisioned.log'), \FILE_IGNORE_NEW_LINES);
        $cleaned = \file($project->path('markers/cleaned.log'), \FILE_IGNORE_NEW_LINES);
        $constructed = \file($project->path('markers/constructed.log'), \FILE_IGNORE_NEW_LINES);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that(\is_array($provisioned) ? $provisioned : [])->toHaveCount(2);
        Expect::that(\is_array($cleaned) ? $cleaned : [])->toBe(['cleaned', 'cleaned']);
        Expect::that(\is_array($constructed) ? $constructed : [])
            ->because('each watch run MUST construct a new orchestrator instance and worker instance')
            ->toHaveCount(4);
    }

    #[Test]
    public function watchStartsAndQuitsWhenShellExecIsDisabled(): void
    {
        $project = $this->writeProject();
        $process = GreenlightCli::start(
            $project->directory,
            ['run', '--watch', '--reporter=plain'],
            phpArguments: ['-d', 'disable_functions=shell_exec'],
        );
        $this->cleanup->defer($process->terminate(...));

        $output = $process->readStdoutUntil('Waiting for changes', 20.0);

        Expect::that($output)
            ->because('watch mode starts when PHP disables shell_exec')
            ->toContain('1 test, 1 passed');

        $process->write('q');
        $result = $process->wait(10.0);

        Expect::that($result->exitCode)
            ->because('watch mode exits cleanly when PHP disables shell_exec')
            ->toBe(0);
    }

    #[Test]
    public function fixtureCleanupFailuresAreReportedBeforeWatchModeContinues(): void
    {
        $project = $this->writeProject(failCleanup: true);
        $process = GreenlightCli::start(
            $project->directory,
            ['run', '--watch', '--reporter=plain'],
        );
        $this->cleanup->defer($process->terminate(...));

        $process->readStdoutUntil('Waiting for changes', 20.0);

        $process->write('q');
        $result = $process->wait(10.0);

        Expect::that($result->exitCode)
            ->because('watch mode MUST remain interactive after a fixture cleanup failure')
            ->toBe(0);
        Expect::that($result->output())
            ->because('watch mode MUST report integration fixture cleanup failures')
            ->toContain('Integration fixture teardown failed.')
            ->toContain('intentional fixture cleanup failure');
        Expect::that($this->matches($project->path('markers/resource-*')))
            ->because('watch mode MUST remove orchestrator-owned resources after cleanup failures')
            ->toBe([]);
    }

    #[Test]
    public function coverageIncludePathsTriggerWatchReruns(): void
    {
        $project = $this->writeProject(watchCoverageSource: true);
        $project->writeFile('source/Observed.php', "<?php\n");
        $process = GreenlightCli::start(
            $project->directory,
            ['run', '--watch', '--reporter=plain'],
        );
        $this->cleanup->defer($process->terminate(...));

        $output = $process->readStdoutUntil('Waiting for changes', 20.0);

        Expect::that($output)
            ->because('watch mode MUST complete its initial coverage run')
            ->toContain('1 test, 1 passed');

        $project->writeFile('source/Observed.php', "<?php\n// changed\n");
        $output = $process->readStdoutUntil('Waiting for changes', 20.0);

        Expect::that($output)
            ->because('watch mode MUST observe configured coverage include paths')
            ->toContain('Detected changes')
            ->toContain('1 test, 1 passed');

        $process->write('q');
        $result = $process->wait(10.0);

        Expect::that($result->exitCode)
            ->because('watch mode exits cleanly after a coverage source change')
            ->toBe(0);
    }

    private function writeProject(
        bool $watchCoverageSource = false,
        bool $failCleanup = false,
    ): AcceptanceProject {
        $project = AcceptanceProject::create($this->tempDirectory, 'watch');
        $project->writeFile('markers/.gitkeep', '');
        $project->writeFile('tests/WatchProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace WatchProbe;

            use Greenlight\Attribute\Test;

            final class WatchProbeTest
            {
                #[Test]
                public function passes(): void {}
            }
            PHP);
        $coverage = $watchCoverageSource
            ? "\n    ->coverage(fn(\$coverage) => \$coverage"
                . "\n        ->include(__DIR__ . '/source')"
                . "\n        ->driver('pcov'))"
            : '';
        $markerDirectory = \var_export($project->path('markers'), true);
        $failCleanupValue = $failCleanup ? 'true' : 'false';
        $project->writeFile('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Tests\Fixture\Plugins\IntegrationProbePlugin;

            require_once __DIR__ . '/tests/WatchProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)%s
                ->watch(fn($watch) => $watch->debounceMilliseconds(50))
                ->plugins(
                    static fn(): IntegrationProbePlugin => new IntegrationProbePlugin(%s, failCleanup: %s),
                );
            PHP,
            $coverage,
            $markerDirectory,
            $failCleanupValue,
        ));

        return $project;
    }

    /**
     * @return list<string>
     */
    private function matches(string $pattern): array
    {
        $matches = \glob($pattern);

        return \is_array($matches) ? $matches : [];
    }
}
