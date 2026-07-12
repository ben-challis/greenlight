<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Drives --list-tests, --list-groups, and --list-suites through the real CLI
 * against the discovery fixtures and asserts on exit codes and output lines.
 */
final class ListingTest
{
    #[Test]
    public function listTestsPrintsTheSelectionInPlanOrderWithoutRunning(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig('listing');

        try {
            [$exit, $output] = $this->runCli($project->directory, ['run', '--list-tests']);

            Expect::that($exit)->toBe(0);
            $this->assertContainsLine($output, 'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one');
            $this->assertContainsLine($output, 'Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls');
            $this->assertContainsLine($output, '7 tests');

            // Cheap proxy for "the seed shuffled the plan": ids must stay
            // grouped by class. Alphabetical output would also pass this
            // check; interleaved classes would not.
            $classes = [];

            foreach ($this->testIdLines($output) as $id) {
                $class = \strstr($id, '::', true);

                if ($class !== false && ($classes === [] || $classes[\count($classes) - 1] !== $class)) {
                    $classes[] = $class;
                }
            }

            Expect::that($classes)->toBe(\array_values(\array_unique($classes)));
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function listTestsIsDeterministicAcrossRuns(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig('listing');

        try {
            [, $first] = $this->runCli($project->directory, ['run', '--list-tests']);
            [, $second] = $this->runCli($project->directory, ['run', '--list-tests']);

            Expect::that($second)->toBe($first);
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function listTestsComposesWithExcludeGroup(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig('listing');

        try {
            [$exit, $output] = $this->runCli($project->directory, ['run', '--list-tests', '--exclude-group=slow']);

            Expect::that($exit)->toBe(0);
            $this->assertContainsLine($output, 'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one');
            $this->assertContainsLine($output, '5 tests');
            Expect::that(
                !\in_array('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two', $output, true),
            )->toBeTrue();
            Expect::that(
                !\in_array('Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls', $output, true),
            )->toBeTrue();
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function listTestsComposesWithShardingIntoDisjointSlices(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig('listing');

        try {
            [, $full] = $this->runCli($project->directory, ['run', '--list-tests']);
            [$firstExit, $first] = $this->runCli($project->directory, ['run', '--list-tests', '--shard=1/2']);
            [$secondExit, $second] = $this->runCli($project->directory, ['run', '--list-tests', '--shard=2/2']);

            Expect::that($firstExit)->toBe(0);
            Expect::that($secondExit)->toBe(0);

            $firstIds = $this->testIdLines($first);
            $secondIds = $this->testIdLines($second);

            Expect::that(\array_values(\array_intersect($firstIds, $secondIds)))->toBe([]);

            $union = [...$firstIds, ...$secondIds];
            \sort($union);
            $fullIds = $this->testIdLines($full);
            \sort($fullIds);
            Expect::that($union)->toBe($fullIds);
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function listGroupsPrintsEachGroupWithItsTestCount(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig('listing');

        try {
            [$exit, $output] = $this->runCli($project->directory, ['run', '--list-groups']);

            Expect::that($exit)->toBe(0);
            $this->assertContainsLine($output, 'basic (2 tests)');
            $this->assertContainsLine($output, 'slow (2 tests)');
            $this->assertContainsLine($output, '2 groups');

            [, $second] = $this->runCli($project->directory, ['run', '--list-groups']);
            Expect::that($second)->toBe($output);
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function listSuitesPrintsTheConfiguredSuites(): void
    {
        [$exit, $output] = $this->runCli(
            \dirname(__DIR__, 2),
            ['run', '--list-suites', '--config=tests/Fixture/ConfigFiles/Valid/greenlight.php'],
        );

        Expect::that($exit)->toBe(0);
        $this->assertContainsLine($output, 'unit: tests/Unit');
        $this->assertContainsLine($output, 'integration: tests/Integration [tags: io]');
        $this->assertContainsLine($output, '2 suites');
    }

    #[Test]
    public function listSuitesWithNoSuitesConfiguredPrintsZero(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig('listing');

        try {
            [$exit, $output] = $this->runCli($project->directory, ['run', '--list-suites']);

            Expect::that($exit)->toBe(0);
            $this->assertContainsLine($output, '0 suites');
        } finally {
            $project->remove();
        }
    }

    /**
     * The printed test id lines, in output order.
     *
     * @param list<string> $output
     *
     * @return list<string>
     */
    private function testIdLines(array $output): array
    {
        return \array_values(\array_filter(
            $output,
            static fn(string $line): bool => \str_contains($line, '::'),
        ));
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, list<string>}
     */
    private function runCli(string $cwd, array $arguments): array
    {
        $root = \dirname(__DIR__, 2);

        $parts = [\escapeshellarg(\PHP_BINARY), \escapeshellarg($root . '/bin/greenlight')];

        foreach ($arguments as $argument) {
            $parts[] = \escapeshellarg($argument);
        }

        // Listing output is stdout only; discarding stderr keeps the exact
        // determinism assertions immune to extension noise (Xdebug, ddtrace).
        $command = \sprintf('cd %s && %s 2>/dev/null', \escapeshellarg($cwd), \implode(' ', $parts));

        \exec($command, $output, $exit);

        return [$exit, $output];
    }

    /**
     * @param list<string> $output
     */
    private function assertContainsLine(array $output, string $expected): void
    {
        if (!\in_array($expected, $output, true)) {
            throw new \RuntimeException(\sprintf(
                "Expected output to contain the line '%s'. Got:\n%s",
                $expected,
                \implode("\n", $output),
            ));
        }
    }
}
