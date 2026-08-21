<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class SeedOrderTest
{
    private const array CLASSES = ['A', 'B', 'C', 'D', 'E', 'F'];

    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function theSameSeedProducesTheSameOrderAcrossRuns(): void
    {
        $project = $this->writeProject();
        $first = $this->order($project, '--seed=3');
        $second = $this->order($project, '--seed=3');
        Expect::that($first)->because('the same seed produces the same order across runs')->toBe($second);
    }

    #[Test]
    public function zeroIsAnActiveSeed(): void
    {
        $project = $this->writeProject();
        $plan = GreenlightCli::run($project->directory, ['run', '--dry-run', '--seed=0']);

        Expect::that($plan->exitCode)
            ->because('the dry-run command MUST accept zero as an active seed')
            ->toBe(0);
        Expect::that($plan->stdoutLines())
            ->because('the dry-run plan MUST identify zero as the active random-order seed')
            ->toContain('  order: random (seed 0)');

        $run = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--seed=0']);

        Expect::that($run->exitCode)
            ->because('the run with seed zero MUST complete')
            ->toBe(0);
        Expect::that($run->stdout)
            ->because('the run header MUST announce seed zero')
            ->toContain('seed: 0');
    }

    #[Test]
    public function someSeedReordersTheClassesAwayFromDeclarationOrder(): void
    {
        $project = $this->writeProject();
        $declared = $this->order($project);
        $reordered = false;
        for ($seed = 1; $seed <= 10; $seed++) {
            if ($this->order($project, '--seed=' . $seed) !== $declared) {
                $reordered = true;

                break;
            }
        }
        Expect::that($reordered)->because('some seed reorders the classes away from declaration order')->toBeTrue();
    }

    #[Test]
    public function withoutASeedTheOrderMatchesDeclarationOrder(): void
    {
        $project = $this->writeProject();

        Expect::that($this->order($project))->because('without a seed the order matches declaration order')->toBe($this->declaredOrder());
    }

    #[Test]
    public function anActiveSeedIsAnnouncedInTheRunHeader(): void
    {
        $project = $this->writeProject();
        // Use standard output only. Extension messages on standard error can
        // contain "seed:" and invalidate the negative assertion.
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--seed=7']);
        Expect::that($result->exitCode)
            ->because('the run with seed 7 MUST complete')
            ->toBe(0);
        Expect::that($result->stdout)
            ->because('the seeded run header MUST announce seed 7')
            ->toContain('seed: 7');
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);
        Expect::that($result->exitCode)
            ->because('the run without a seed MUST complete')
            ->toBe(0);
        Expect::that($result->stdout)
            ->because('the unseeded run header MUST omit the seed')
            ->not()
            ->toContain('seed:');
    }

    /**
     * @return list<string>
     */
    private function declaredOrder(): array
    {
        return \array_map(
            static fn(string $letter): string => \sprintf('SeedOrderProbe\\%sProbeTest::one', $letter),
            self::CLASSES,
        );
    }

    /**
     * Reads --list-tests to prevent the start of six classes for each seed.
     * Uses standard output so extension messages cannot change the order.
     *
     * @return list<string>
     */
    private function order(AcceptanceProject $project, string ...$flags): array
    {
        $lines = GreenlightCli::run($project->directory, \array_values(['list-tests', ...$flags]))->stdoutLines();

        return \array_values(\array_filter($lines, static fn(string $line): bool => \str_contains($line, '::')));
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'seed-order');
        $files = [];

        foreach (self::CLASSES as $letter) {
            $file = \sprintf('tests/%sProbeTest.php', $letter);

            $project->writeFile($file, <<<PHP
                <?php

                declare(strict_types=1);

                namespace SeedOrderProbe;

                use Greenlight\Attribute\Test;

                final class {$letter}ProbeTest
                {
                    #[Test]
                    public function one(): void {}
                }
                PHP);

            $files[] = $file;
        }

        $project->configureWithTestFiles($files);

        return $project;
    }
}
