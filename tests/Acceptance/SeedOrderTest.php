<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Drives --seed through the real CLI against a project with six classes,
 * using --list-tests to read back the plan order without executing anything.
 */
final class SeedOrderTest
{
    private const array CLASSES = ['A', 'B', 'C', 'D', 'E', 'F'];

    #[Test]
    public function theSameSeedProducesTheSameOrderAcrossRuns(): void
    {
        $project = $this->writeProject();

        try {
            $first = $this->order($project, '--seed=3');
            $second = $this->order($project, '--seed=3');

            Expect::that($first)->toBe($second);
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function someSeedReordersTheClassesAwayFromDeclarationOrder(): void
    {
        $project = $this->writeProject();

        try {
            $declared = $this->order($project);
            $reordered = false;

            for ($seed = 1; $seed <= 10; $seed++) {
                if ($this->order($project, '--seed=' . $seed) !== $declared) {
                    $reordered = true;

                    break;
                }
            }

            Expect::that($reordered)->toBeTrue();
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function withoutASeedTheOrderMatchesDeclarationOrder(): void
    {
        $project = $this->writeProject();

        try {
            Expect::that($this->order($project))->toBe($this->declaredOrder());
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function anActiveSeedIsAnnouncedInTheRunHeader(): void
    {
        $project = $this->writeProject();

        try {
            // Stdout only: extension noise on stderr could contain "seed:"
            // and break the negative assertion below.
            [$exit, $output] = $project->runStdout('run', '--reporter=plain', '--seed=7');

            Expect::that($exit)->toBe(0)->and($output)->toContain('seed: 7');

            [$exit, $output] = $project->runStdout('run', '--reporter=plain');

            Expect::that($exit)->toBe(0)->and($output)->not()->toContain('seed:');
        } finally {
            $project->remove();
        }
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
     * The class order the plan would execute in, read from --list-tests
     * rather than a run, so reordering is asserted without paying for
     * six class boots per seed. Stdout only: the exact order comparisons
     * must stay immune to extension noise on stderr (Xdebug, ddtrace).
     *
     * @return list<string>
     */
    private function order(AcceptanceProject $project, string ...$flags): array
    {
        [, $lines] = $project->runLinesStdout('list-tests', ...$flags);

        return \array_values(\array_filter($lines, static fn(string $line): bool => \str_contains($line, '::')));
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create('seed-order');
        $files = [];

        foreach (self::CLASSES as $letter) {
            $file = \sprintf('tests/%sProbeTest.php', $letter);

            $project->write($file, <<<PHP
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

        $project->writeConfig($files);

        return $project;
    }
}
