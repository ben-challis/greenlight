<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

/**
 * Drives --list-tests, --list-groups, and --list-suites through the real CLI
 * against the discovery fixtures and asserts on exit codes and output lines.
 */
final readonly class ListingTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function listTestsPrintsTheSelectionInPlanOrderWithoutRunning(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'listing');
        $result = GreenlightCli::run($project->directory, ['run', '--list-tests']);
        $output = $result->stdoutLines();
        Expect::that($result->exitCode)->toBe(0);
        Expect::that($output)
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one')
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls')
            ->toContain('7 tests');
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
    }

    #[Test]
    public function listTestsIsDeterministicAcrossRuns(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'listing');
        $first = GreenlightCli::run($project->directory, ['run', '--list-tests'])->stdoutLines();
        $second = GreenlightCli::run($project->directory, ['run', '--list-tests'])->stdoutLines();
        Expect::that($second)->toBe($first);
    }

    #[Test]
    public function listTestsComposesWithExcludeGroup(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'listing');
        $result = GreenlightCli::run($project->directory, ['run', '--list-tests', '--exclude-group=slow']);
        $output = $result->stdoutLines();
        Expect::that($result->exitCode)->toBe(0);
        Expect::that($output)
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one')
            ->toContain('5 tests')
            ->not()->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two')
            ->not()->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls');
    }

    #[Test]
    public function listTestsComposesWithShardingIntoDisjointSlices(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'listing');
        $full = GreenlightCli::run($project->directory, ['run', '--list-tests'])->stdoutLines();
        $firstResult = GreenlightCli::run($project->directory, ['run', '--list-tests', '--shard=1/2']);
        $secondResult = GreenlightCli::run($project->directory, ['run', '--list-tests', '--shard=2/2']);
        Expect::that($firstResult->exitCode)->toBe(0);
        Expect::that($secondResult->exitCode)->toBe(0);
        $first = $firstResult->stdoutLines();
        $second = $secondResult->stdoutLines();
        $firstIds = $this->testIdLines($first);
        $secondIds = $this->testIdLines($second);
        Expect::that(\array_values(\array_intersect($firstIds, $secondIds)))->toBe([]);
        $union = [...$firstIds, ...$secondIds];
        \sort($union);
        $fullIds = $this->testIdLines($full);
        \sort($fullIds);
        Expect::that($union)->toBe($fullIds);
    }

    #[Test]
    public function listGroupsPrintsEachGroupWithItsTestCount(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'listing');
        $result = GreenlightCli::run($project->directory, ['run', '--list-groups']);
        $output = $result->stdoutLines();
        Expect::that($result->exitCode)->toBe(0);
        Expect::that($output)
            ->toContain('basic (2 tests)')
            ->toContain('slow (2 tests)')
            ->toContain('2 groups');
        $second = GreenlightCli::run($project->directory, ['run', '--list-groups'])->stdoutLines();
        Expect::that($second)->toBe($output);
    }

    #[Test]
    public function listSuitesPrintsTheConfiguredSuites(): void
    {
        $result = GreenlightCli::run(
            \dirname(__DIR__, 2),
            ['run', '--list-suites', '--config=tests/Fixture/ConfigFiles/Valid/greenlight.php'],
        );
        $output = $result->stdoutLines();

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($output)
            ->toContain('unit: tests/Unit')
            ->toContain('integration: tests/Integration [tags: io]')
            ->toContain('2 suites');
    }

    #[Test]
    public function listSuitesWithNoSuitesConfiguredPrintsZero(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'listing');
        $result = GreenlightCli::run($project->directory, ['run', '--list-suites']);
        $output = $result->stdoutLines();
        Expect::that($result->exitCode)->toBe(0)
            ->and($output)->toContain('0 suites');
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

}
