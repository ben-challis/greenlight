<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class RepeatTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function repeatRunsThePlanTheRequestedNumberOfTimes(): void
    {
        $project = $this->writeProject(passing: true);
        $result = $this->run($project, [], '--repeat=3');
        Expect::that($result->exitCode)->toBe(0)
            ->and($result->output())->toContain('Repeat: iteration 1 of 3')
            ->toContain('Repeat: iteration 2 of 3')
            ->toContain('Repeat: iteration 3 of 3')
            ->toContain('Repeat: 3 iterations, all passed');
    }

    #[Test]
    public function repeatReportsEveryFailingIteration(): void
    {
        $project = $this->writeProject(passing: false);
        $result = $this->run($project, [], '--repeat=2');
        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('Repeat: iteration 2 of 2')
            ->toContain('Repeat: failed on iteration(s) 1, 2');
    }

    #[Test]
    public function repeatUntilFailureStopsAtTheFirstFailingIteration(): void
    {
        $project = $this->writeFlakyProject();
        $state = $project->path('repeat-state');
        $result = $this->run($project, ['GREENLIGHT_REPEAT_STATE' => $state], '--repeat-until-failure');
        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('Repeat: iteration 3 of at most 100')
            ->toContain('Repeat: failed on iteration(s) 3')
            ->not()->toContain('Repeat: iteration 4');
    }

    #[Test]
    public function failedRerunsEveryTestThatFlakedDuringRepeat(): void
    {
        $project = $this->writeFlakyProject();
        $state = $project->path('repeat-state');
        $result = $this->run($project, ['GREENLIGHT_REPEAT_STATE' => $state], '--repeat-until-failure');
        Expect::that($result->exitCode)->toBe(1);
        // The recorded state must keep the flake even though earlier
        // iterations passed, so --failed replays exactly that test.
        $result = $this->run($project, ['GREENLIGHT_REPEAT_STATE' => $state], '--failed');
        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('failsOnTheThirdRun')
            ->toContain('1 test');
    }

    #[Test]
    public function repeatComposesWithFilter(): void
    {
        $project = $this->writeProject(passing: true);
        $result = $this->run($project, [], '--repeat=2', '--filter=firstProbe');
        Expect::that($result->exitCode)->toBe(0)
            ->and($result->output())->toContain('Repeat: 2 iterations, all passed')
            ->and(\substr_count($result->output(), '1 test, 1 passed'))->toBe(2);
    }

    #[Test]
    public function watchCannotBeCombinedWithRepeat(): void
    {
        $project = $this->writeProject(passing: true);
        $result = $this->run($project, [], '--watch', '--repeat=2');
        Expect::that($result->exitCode)->toBe(64)->and($result->output())->toContain('cannot be combined');
        $result = $this->run($project, [], '--watch', '--repeat-until-failure');
        Expect::that($result->exitCode)->toBe(64)->and($result->output())->toContain('cannot be combined');
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

    private function writeProjectWithTestClass(string $code): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'repeat');
        $project->write('tests/RepeatProbeTest.php', $code);
        $project->writeConfig(['tests/RepeatProbeTest.php']);

        return $project;
    }
}
