<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class RepeatTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function repeatRunsThePlanTheRequestedNumberOfTimes(): void
    {
        $project = $this->writeProject(passing: true);
        $result = $this->run($project, [], '--repeat=3');
        Expect::that($result->exitCode)->because('repeat runs the plan the requested number of times')->toBe(0);
        Expect::that($result->output())
            ->because('repeat runs the plan the requested number of times')
            ->toContain('Repeat: iteration 1 of 3')
            ->toContain('Repeat: iteration 2 of 3')
            ->toContain('Repeat: iteration 3 of 3')
            ->toContain('Repeat: 3 iterations, all passed');
    }

    #[Test]
    public function repeatReportsEveryFailingIteration(): void
    {
        $project = $this->writeProject(passing: false);
        $result = $this->run($project, [], '--repeat=2');
        Expect::that($result->exitCode)->because('repeat reports every failing iteration')->toBe(1);
        Expect::that($result->output())
            ->because('repeat reports every failing iteration')
            ->toContain('Repeat: iteration 2 of 2')
            ->toContain('Repeat: failed iterations: 1, 2');
    }

    #[Test]
    public function repeatUntilFailureStopsAtTheFirstFailingIteration(): void
    {
        $project = $this->writeFlakyProject();
        $state = $project->path('repeat-state');
        $result = $this->run($project, ['GREENLIGHT_REPEAT_STATE' => $state], '--repeat-until-failure');
        Expect::that($result->exitCode)->because('repeat until failure stops at the first failing iteration')->toBe(1);
        Expect::that($result->output())
            ->because('repeat until failure stops at the first failing iteration')
            ->toContain('Repeat: iteration 3 of at most 100')
            ->toContain('Repeat: failed iterations: 3')
            ->not()->toContain('Repeat: iteration 4');
    }

    #[Test]
    public function failedRerunsEveryTestThatFlakedDuringRepeat(): void
    {
        $project = $this->writeFlakyProject();
        $state = $project->path('repeat-state');
        $result = $this->run($project, ['GREENLIGHT_REPEAT_STATE' => $state], '--repeat-until-failure');
        Expect::that($result->exitCode)->because('failed reruns every test that flaked during repeat')->toBe(1);
        // The recorded state MUST keep the intermittent failure after earlier
        // iterations pass. Thus, --failed runs only that test again.
        $result = $this->run($project, ['GREENLIGHT_REPEAT_STATE' => $state], '--failed');
        Expect::that($result->exitCode)->because('failed reruns every test that flaked during repeat')->toBe(1);
        Expect::that($result->output())
            ->because('failed reruns every test that flaked during repeat')
            ->toContain('failsOnTheThirdRun')
            ->toContain('1 test');
    }

    #[Test]
    public function failedRerunsFailuresFromDifferentRepeatIterations(): void
    {
        $project = $this->writeFailuresAcrossIterationsProject();
        $state = $project->path('repeat-state');
        $environment = ['GREENLIGHT_REPEAT_STATE' => $state];

        $result = $this->run($project, $environment, '--repeat=3');
        Expect::that($result->exitCode)->because('repeat collects failures from different iterations')->toBe(1);
        Expect::that($result->output())
            ->because('repeat collects failures from different iterations')
            ->toContain('Repeat: failed iterations: 1, 2');

        $result = $this->run($project, $environment, '--failed');
        Expect::that($result->exitCode)->because('failed reruns failures from different repeat iterations')->toBe(0);
        Expect::that($result->output())
            ->because('failed reruns failures from different repeat iterations')
            ->toContain('2 tests, 2 passed');
    }

    #[Test]
    public function repeatComposesWithFilter(): void
    {
        $project = $this->writeProject(passing: true);
        $result = $this->run($project, [], '--repeat=2', '--filter=firstProbe');
        Expect::that($result->exitCode)->because('repeat composes with filter')->toBe(0);
        Expect::that($result->output())->because('repeat composes with filter')->toContain('Repeat: 2 iterations, all passed');
        Expect::that(\substr_count($result->output(), '1 test, 1 passed'))->because('repeat composes with filter')->toBe(2);
    }

    #[Test]
    #[DataSet('repeatOptions')]
    public function watchCannotBeCombinedWithRepeat(string $repeatOption): void
    {
        $project = $this->writeProject(passing: true);
        $result = $this->run($project, [], '--watch', $repeatOption);

        Expect::that($result->exitCode)->because('watch cannot be combined with repeat')->toBe(64);
        Expect::that($result->output())->because('watch cannot be combined with repeat')->toContain('Do not use --watch with');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function repeatOptions(): iterable
    {
        yield 'fixed repeat count' => ['--repeat=2'];
        yield 'repeat until failure' => ['--repeat-until-failure'];
    }

    /**
     * @param array<string, string> $environment
     */
    private function run(AcceptanceProject $project, array $environment, string ...$flags): ProcessResult
    {
        return GreenlightCli::run(
            $project->directory,
            \array_values(['run', '--reporter=plain', ...$flags]),
            $environment,
        );
    }

    private function writeProject(bool $passing): AcceptanceProject
    {
        $body = $passing
            ? 'public function secondProbe(): void {}'
            : <<<'PHP'
                public function secondProbe(): never
                    {
                        throw new \RuntimeException('intentional repeat failure');
                    }
                PHP;

        return $this->writeProjectWithTestClass(<<<PHP
            <?php

            declare(strict_types=1);

            namespace RepeatProbe;

            use Greenlight\Attribute\Test;

            final class RepeatProbeTest
            {
                #[Test]
                public function firstProbe(): void {}

                #[Test]
                {$body}
            }
            PHP);
    }

    private function writeFlakyProject(): AcceptanceProject
    {
        return $this->writeProjectWithTestClass(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace RepeatProbe;

            use Greenlight\Attribute\Test;

            final class RepeatProbeTest
            {
                #[Test]
                public function failsOnTheThirdRun(): void
                {
                    $path = \getenv('GREENLIGHT_REPEAT_STATE');

                    if (!\is_string($path) || $path === '') {
                        throw new \RuntimeException('GREENLIGHT_REPEAT_STATE is not set');
                    }

                    $count = \is_file($path) ? (int) \file_get_contents($path) : 0;
                    $count++;
                    \file_put_contents($path, (string) $count);

                    if ($count >= 3) {
                        throw new \RuntimeException('flaked on run ' . $count);
                    }
                }
            }
            PHP);
    }

    private function writeFailuresAcrossIterationsProject(): AcceptanceProject
    {
        return $this->writeProjectWithTestClass(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace RepeatProbe;

            use Greenlight\Attribute\Test;

            final class RepeatProbeTest
            {
                #[Test]
                public function failsOnTheFirstRun(): void
                {
                    if ($this->runNumber(__FUNCTION__) === 1) {
                        throw new \RuntimeException('failed on the first run');
                    }
                }

                #[Test]
                public function failsOnTheSecondRun(): void
                {
                    if ($this->runNumber(__FUNCTION__) === 2) {
                        throw new \RuntimeException('failed on the second run');
                    }
                }

                private function runNumber(string $test): int
                {
                    $directory = \getenv('GREENLIGHT_REPEAT_STATE');

                    if (!\is_string($directory) || $directory === '') {
                        throw new \RuntimeException('GREENLIGHT_REPEAT_STATE is not set');
                    }

                    if (!\is_dir($directory)) {
                        \mkdir($directory);
                    }

                    $path = $directory . '/' . $test;
                    $count = \is_file($path) ? (int) \file_get_contents($path) : 0;
                    $count++;
                    \file_put_contents($path, (string) $count);

                    return $count;
                }
            }
            PHP);
    }

    private function writeProjectWithTestClass(string $code): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'repeat');
        $project->writeFile('tests/RepeatProbeTest.php', $code);
        $project->configureWithTestFiles(['tests/RepeatProbeTest.php']);

        return $project;
    }
}
