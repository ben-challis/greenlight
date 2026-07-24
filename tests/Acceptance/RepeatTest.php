<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Drives --repeat and --repeat-until-failure through the real CLI against a
 * throwaway project in a unique temp directory. The flaky fixture keeps its
 * run count in a file named by the GREENLIGHT_REPEAT_STATE environment
 * variable, so each test controls exactly which iteration fails.
 */
final readonly class RepeatTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function repeatRunsThePlanTheRequestedNumberOfTimes(): void
    {
        $project = $this->writeProject(passing: true);
        [$exit, $output] = $this->run($project, [], '--repeat=3');
        Expect::that($exit)->toBe(0)
            ->and($output)->toContain('Repeat: iteration 1 of 3')
            ->and($output)->toContain('Repeat: iteration 2 of 3')
            ->and($output)->toContain('Repeat: iteration 3 of 3')
            ->and($output)->toContain('Repeat: 3 iterations, all passed');
    }

    #[Test]
    public function repeatReportsEveryFailingIteration(): void
    {
        $project = $this->writeProject(passing: false);
        [$exit, $output] = $this->run($project, [], '--repeat=2');
        Expect::that($exit)->toBe(1)
            ->and($output)->toContain('Repeat: iteration 2 of 2')
            ->and($output)->toContain('Repeat: failed on iteration(s) 1, 2');
    }

    #[Test]
    public function repeatUntilFailureStopsAtTheFirstFailingIteration(): void
    {
        $project = $this->writeFlakyProject();
        $state = $project->path('repeat-state');
        [$exit, $output] = $this->run($project, ['GREENLIGHT_REPEAT_STATE' => $state], '--repeat-until-failure');
        Expect::that($exit)->toBe(1)
            ->and($output)->toContain('Repeat: iteration 3 of at most 100')
            ->and($output)->toContain('Repeat: failed on iteration(s) 3')
            ->and($output)->not()->toContain('Repeat: iteration 4');
    }

    #[Test]
    public function failedRerunsEveryTestThatFlakedDuringRepeat(): void
    {
        $project = $this->writeFlakyProject();
        $state = $project->path('repeat-state');
        [$exit] = $this->run($project, ['GREENLIGHT_REPEAT_STATE' => $state], '--repeat-until-failure');
        Expect::that($exit)->toBe(1);
        // The recorded state must keep the flake even though earlier
        // iterations passed, so --failed replays exactly that test.
        [$exit, $output] = $this->run($project, ['GREENLIGHT_REPEAT_STATE' => $state], '--failed');
        Expect::that($exit)->toBe(1)
            ->and($output)->toContain('failsOnTheThirdRun')
            ->and($output)->toContain('1 test');
    }

    #[Test]
    public function repeatComposesWithFilter(): void
    {
        $project = $this->writeProject(passing: true);
        [$exit, $output] = $this->run($project, [], '--repeat=2', '--filter=firstProbe');
        Expect::that($exit)->toBe(0)
            ->and($output)->toContain('Repeat: 2 iterations, all passed')
            ->and(\substr_count($output, '1 test, 1 passed'))->toBe(2);
    }

    #[Test]
    public function watchCannotBeCombinedWithRepeat(): void
    {
        $project = $this->writeProject(passing: true);
        [$exit, $output] = $this->run($project, [], '--watch', '--repeat=2');
        Expect::that($exit)->toBe(64)->and($output)->toContain('cannot be combined');
        [$exit, $output] = $this->run($project, [], '--watch', '--repeat-until-failure');
        Expect::that($exit)->toBe(64)->and($output)->toContain('cannot be combined');
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array{int, string}
     */
    private function run(AcceptanceProject $project, array $environment, string ...$flags): array
    {
        $root = \dirname(__DIR__, 2);
        $parts = [];

        foreach ($environment as $name => $value) {
            $parts[] = $name . '=' . \escapeshellarg($value);
        }

        $parts = [...$parts, \escapeshellarg(\PHP_BINARY), \escapeshellarg($root . '/bin/greenlight'), 'run', '--reporter=plain'];

        foreach ($flags as $flag) {
            $parts[] = \escapeshellarg($flag);
        }

        $command = \sprintf('cd %s && %s 2>&1', \escapeshellarg($project->directory), \implode(' ', $parts));
        \exec($command, $output, $exit);

        return [$exit, \implode("\n", $output)];
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
        $project->write('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/RepeatProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1);
            PHP);

        return $project;
    }
}
