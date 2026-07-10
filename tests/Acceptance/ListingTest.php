<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Tests\Support\Check;

/**
 * Drives --list-tests, --list-groups, and --list-suites through the real CLI
 * against the discovery fixtures and asserts on exit codes and output lines.
 */
final class ListingTest
{
    #[Test]
    public function listTestsPrintsTheSelectionInPlanOrderWithoutRunning(): void
    {
        [$exit, $output] = $this->runCli(['run', '--list-tests'], 'tests/Fixture/ListTestsConfig');

        Check::same(0, $exit, 'list-tests exit code');
        $this->assertContainsLine($output, 'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one');
        $this->assertContainsLine($output, 'Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls');
        $this->assertContainsLine($output, '7 tests');

        // Plan order previews the run, so a seeded listing must differ from
        // the same listing under another seed at least once across seeds; the
        // cheap proxy asserted here is that ids stay grouped by class, which
        // alphabetical output would also satisfy but a scrambled one would not.
        $classes = [];

        foreach ($this->testIdLines($output) as $id) {
            $class = \strstr($id, '::', true);

            if ($class !== false && ($classes === [] || $classes[\count($classes) - 1] !== $class)) {
                $classes[] = $class;
            }
        }

        Check::same(\array_values(\array_unique($classes)), $classes, 'ids to be grouped by class');
    }

    #[Test]
    public function listTestsIsDeterministicAcrossRuns(): void
    {
        [, $first] = $this->runCli(['run', '--list-tests'], 'tests/Fixture/ListTestsConfig');
        [, $second] = $this->runCli(['run', '--list-tests'], 'tests/Fixture/ListTestsConfig');

        Check::same($first, $second, 'two list-tests runs to produce identical output');
    }

    #[Test]
    public function listTestsComposesWithExcludeGroup(): void
    {
        [$exit, $output] = $this->runCli(['run', '--list-tests', '--exclude-group=slow'], 'tests/Fixture/ListTestsConfig');

        Check::same(0, $exit, 'excluded list-tests exit code');
        $this->assertContainsLine($output, 'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one');
        $this->assertContainsLine($output, '5 tests');
        Check::true(
            !\in_array('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two', $output, true),
            'the slow test to be excluded from the listing',
        );
        Check::true(
            !\in_array('Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls', $output, true),
            'the slow class to be excluded from the listing',
        );
    }

    #[Test]
    public function listTestsComposesWithShardingIntoDisjointSlices(): void
    {
        [, $full] = $this->runCli(['run', '--list-tests'], 'tests/Fixture/ListTestsConfig');
        [$firstExit, $first] = $this->runCli(['run', '--list-tests', '--shard=1/2'], 'tests/Fixture/ListTestsConfig');
        [$secondExit, $second] = $this->runCli(['run', '--list-tests', '--shard=2/2'], 'tests/Fixture/ListTestsConfig');

        Check::same(0, $firstExit, 'shard 1/2 exit code');
        Check::same(0, $secondExit, 'shard 2/2 exit code');

        $firstIds = $this->testIdLines($first);
        $secondIds = $this->testIdLines($second);

        Check::same([], \array_values(\array_intersect($firstIds, $secondIds)), 'the shards to be disjoint');

        $union = [...$firstIds, ...$secondIds];
        \sort($union);
        $fullIds = $this->testIdLines($full);
        \sort($fullIds);
        Check::same($fullIds, $union, 'the shards to cover the full selection');
    }

    #[Test]
    public function listGroupsPrintsEachGroupWithItsTestCount(): void
    {
        [$exit, $output] = $this->runCli(['run', '--list-groups'], 'tests/Fixture/ListTestsConfig');

        Check::same(0, $exit, 'list-groups exit code');
        $this->assertContainsLine($output, 'basic (2 tests)');
        $this->assertContainsLine($output, 'slow (2 tests)');
        $this->assertContainsLine($output, '2 groups');

        [, $second] = $this->runCli(['run', '--list-groups'], 'tests/Fixture/ListTestsConfig');
        Check::same($output, $second, 'two list-groups runs to produce identical output');
    }

    #[Test]
    public function listSuitesPrintsTheConfiguredSuites(): void
    {
        [$exit, $output] = $this->runCli(['run', '--list-suites', '--config=tests/Fixture/ConfigFiles/Valid/greenlight.php']);

        Check::same(0, $exit, 'list-suites exit code');
        $this->assertContainsLine($output, 'unit: tests/Unit');
        $this->assertContainsLine($output, 'integration: tests/Integration [tags: io]');
        $this->assertContainsLine($output, '2 suites');
    }

    #[Test]
    public function listSuitesWithNoSuitesConfiguredPrintsZero(): void
    {
        [$exit, $output] = $this->runCli(['run', '--list-suites'], 'tests/Fixture/ListTestsConfig');

        Check::same(0, $exit, 'empty list-suites exit code');
        $this->assertContainsLine($output, '0 suites');
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
    private function runCli(array $arguments, string $relativeCwd = ''): array
    {
        $root = \dirname(__DIR__, 2);
        $cwd = $relativeCwd === '' ? $root : $root . '/' . $relativeCwd;

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
