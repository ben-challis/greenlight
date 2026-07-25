<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class SeedOrderTest
{
    private const array CLASSES = ['A', 'B', 'C', 'D', 'E', 'F'];

    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function theSameSeedProducesTheSameOrderAcrossRuns(): void
    {
        $project = $this->writeProject();
        $first = $this->order($project, '--seed=3');
        $second = $this->order($project, '--seed=3');
        Expect::that($first)->toBe($second);
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
        Expect::that($reordered)->toBeTrue();
    }

    #[Test]
    public function withoutASeedTheOrderMatchesDeclarationOrder(): void
    {
        $project = $this->writeProject();

        Expect::that($this->order($project))->toBe($this->declaredOrder());
    }

    #[Test]
    public function anActiveSeedIsAnnouncedInTheRunHeader(): void
    {
        $project = $this->writeProject();
        // Stdout only: extension noise on stderr could contain "seed:"
        // and break the negative assertion below.
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--seed=7']);
        Expect::that($result->exitCode)->toBe(0)->and($result->stdout)->toContain('seed: 7');
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);
        Expect::that($result->exitCode)->toBe(0)->and($result->stdout)->not()->toContain('seed:');
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
     * Reads --list-tests to avoid booting six classes per seed. Uses stdout
     * so extension noise on stderr cannot affect the order.
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
