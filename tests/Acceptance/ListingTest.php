<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ListingTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function listTestsPrintsTheSelectionInPlanOrderWithoutRunning(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'listing');
        $result = GreenlightCli::run($project->directory, ['run', '--list-tests']);
        $output = $result->stdoutLines();
        Expect::that($result->exitCode)->because('--list-tests prints the selection in plan order and does not run tests')->toBe(0);
        Expect::that($output)->because('--list-tests prints the selection in plan order and does not run tests')
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one')
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls')
            ->toContain('7 tests');
        // This check verifies that test IDs for one class remain adjacent.
        // Alphabetical output also passes, but interleaved classes do not.
        $classes = [];
        foreach ($this->testIdLines($output) as $id) {
            $class = \strstr($id, '::', true);

            if ($class !== false && ($classes === [] || $classes[\count($classes) - 1] !== $class)) {
                $classes[] = $class;
            }
        }
        Expect::that($classes)->because('--list-tests prints the selection in plan order and does not run tests')->toBe(\array_values(\array_unique($classes)));
    }

    #[Test]
    public function listTestsIsDeterministicAcrossRuns(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'listing');
        $first = GreenlightCli::run($project->directory, ['run', '--list-tests'])->stdoutLines();
        $second = GreenlightCli::run($project->directory, ['run', '--list-tests'])->stdoutLines();
        Expect::that($second)->because('list tests is deterministic across runs')->toBe($first);
    }

    #[Test]
    public function listTestsReportsDiscoveryFailures(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'listing-discovery-error');
        $testFile = $project->path('tests/NoClassTest.php');
        $project->writeFile('tests/NoClassTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ListingDiscoveryError;

            function helper(): void {}
            PHP);
        $project->configureWithTestFiles(['tests/NoClassTest.php']);

        $result = GreenlightCli::run($project->directory, ['run', '--list-tests', '--no-ansi']);

        Expect::that($result->exitCode)
            ->because('list tests MUST report discovery failures')
            ->toBe(1);
        Expect::that($result->stderr)
            ->toBe(\sprintf(
                'greenlight: Test file "%s" does not declare a class, interface, trait, or enum.',
                $testFile,
            ));
    }

    #[Test]
    public function listTestsComposesWithExcludeGroup(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'listing');
        $result = GreenlightCli::run($project->directory, ['run', '--list-tests', '--exclude-group=slow']);
        $output = $result->stdoutLines();
        Expect::that($result->exitCode)->because('list tests composes with exclude group')->toBe(0);
        Expect::that($output)->because('list tests composes with exclude group')
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one')
            ->toContain('5 tests')
            ->not()->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two')
            ->not()->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls');
    }

    #[Test]
    public function listTestsComposesWithShardingIntoDisjointSlices(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'listing');
        $full = GreenlightCli::run($project->directory, ['run', '--list-tests'])->stdoutLines();
        $firstResult = GreenlightCli::run($project->directory, ['run', '--list-tests', '--shard=1/2']);
        $secondResult = GreenlightCli::run($project->directory, ['run', '--list-tests', '--shard=2/2']);
        Expect::that($firstResult->exitCode)->because('list tests composes with sharding into disjoint slices')->toBe(0);
        Expect::that($secondResult->exitCode)->because('list tests composes with sharding into disjoint slices')->toBe(0);
        $first = $firstResult->stdoutLines();
        $second = $secondResult->stdoutLines();
        $firstIds = $this->testIdLines($first);
        $secondIds = $this->testIdLines($second);
        Expect::that(\array_values(\array_intersect($firstIds, $secondIds)))->because('list tests composes with sharding into disjoint slices')->toBe([]);
        $union = [...$firstIds, ...$secondIds];
        \sort($union);
        $fullIds = $this->testIdLines($full);
        \sort($fullIds);
        Expect::that($union)->because('list tests composes with sharding into disjoint slices')->toBe($fullIds);
    }

    #[Test]
    public function listGroupsPrintsEachGroupWithItsTestCount(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'listing');
        $result = GreenlightCli::run($project->directory, ['run', '--list-groups']);
        $output = $result->stdoutLines();
        Expect::that($result->exitCode)->because('list groups prints each group with its test count')->toBe(0);
        Expect::that($output)->because('list groups prints each group with its test count')
            ->toContain('basic (2 tests)')
            ->toContain('slow (2 tests)')
            ->toContain('2 groups');
        $second = GreenlightCli::run($project->directory, ['run', '--list-groups'])->stdoutLines();
        Expect::that($second)->because('list groups prints each group with its test count')->toBe($output);
    }

    #[Test]
    public function listSuitesPrintsTheConfiguredSuites(): void
    {
        $result = GreenlightCli::run(
            \dirname(__DIR__, 2),
            ['run', '--list-suites', '--config=tests/Fixture/ConfigFiles/Valid/greenlight.php'],
        );
        $output = $result->stdoutLines();

        Expect::that($result->exitCode)->because('list suites prints the configured suites')->toBe(0);
        Expect::that($output)->because('list suites prints the configured suites')
            ->toContain('unit: tests/Unit')
            ->toContain('integration: tests/Integration [tags: io]')
            ->toContain('2 suites');
    }

    #[Test]
    public function listSuitesWithNoSuitesConfiguredPrintsZero(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'listing');
        $result = GreenlightCli::run($project->directory, ['run', '--list-suites']);
        $output = $result->stdoutLines();
        Expect::that($result->exitCode)->because('list suites with no suites configured prints zero')->toBe(0);
        Expect::that($output)->toContain('0 suites');
    }

    /**
     * @param list<string> $output
     *
     * @return list<string> printed test ids in output order
     */
    private function testIdLines(array $output): array
    {
        return \array_values(\array_filter(
            $output,
            static fn(string $line): bool => \str_contains($line, '::'),
        ));
    }

}
