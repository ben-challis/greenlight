<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tools;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

require_once __DIR__ . '/../../../tools/benchmark.php';

final readonly class BenchmarkTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function parsesExplicitBenchmarkOptions(): void
    {
        $options = \benchmarkParseOptions([
            'shape' => 'many-isolated',
            'scale' => '2',
            'workers' => '3',
            'warmups' => '2',
            'runs' => '5',
            'seed' => '-17',
            'pause-ms' => '250',
            'format' => 'json',
            'with-comparisons' => false,
        ]);

        Expect::that($options)->toBe([
            'shapes' => ['many-isolated'],
            'scale' => 2,
            'workers' => 3,
            'warmups' => 2,
            'runs' => 5,
            'seed' => -17,
            'pauseMs' => 250,
            'format' => 'json',
            'withComparisons' => true,
        ]);
    }

    #[Test]
    public function scheduleIsReproducibleAndAlternatesEachPairOfRounds(): void
    {
        $configurationIds = ['parallel', 'one', 'phpunit', 'paratest', 'pest', 'pest-parallel'];
        $schedule = \benchmarkSchedule($configurationIds, 12, 731, 'many-fast:sample');

        Expect::that($schedule)->because('the same seed MUST reproduce the configuration order')
            ->toBe(\benchmarkSchedule($configurationIds, 12, 731, 'many-fast:sample'));
        Expect::that($schedule[1])->because('the second round MUST reverse the first round')
            ->toBe(\array_reverse($schedule[0]));
        Expect::that($schedule[3])->because('the fourth round MUST reverse the third round')
            ->toBe(\array_reverse($schedule[2]));

        foreach ($schedule as $order) {
            \sort($order);
            $expected = $configurationIds;
            \sort($expected);

            Expect::that($order)->because('each round MUST contain each configuration once')->toBe($expected);
        }

        foreach ($configurationIds as $configurationId) {
            $configurationCount = \count($configurationIds);

            for ($position = 0; $position < $configurationCount; ++$position) {
                $positionCount = \count(\array_filter(
                    $schedule,
                    static fn(array $order): bool => $order[$position] === $configurationId,
                ));
                Expect::that($positionCount)
                    ->because('the default sample count MUST put each configuration in each position twice')
                    ->toBe(2);
            }
        }
    }

    #[Test]
    public function distributionReportsRobustLocationAndVariationStatistics(): void
    {
        Expect::that(\benchmarkDistribution([9.0, 1.0, 5.0, 3.0]))->toBe([
            'firstQuartile' => 2.0,
            'median' => 4.0,
            'thirdQuartile' => 7.0,
            'relativeMadPercent' => 50.0,
        ]);
    }

    /** @param array<string, string|array<mixed>|false> $options */
    #[Test]
    #[DataSet('invalidOptions')]
    public function rejectsInvalidBenchmarkOptions(array $options, string $message): void
    {
        Expect::that(static fn() => \benchmarkParseOptions($options))
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{array<string, string|array<mixed>|false>, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'unknown shape' => [
            ['shape' => 'unknown'],
            'Unknown benchmark shape "unknown". Use one of: many-fast, few-slow, giant-dataset, mixed, many-isolated, recycle-one, resource-constrained, skewed-bootstrap, chatty-diagnostics, coverage-heavy.',
        ];
        yield 'invalid run count' => [
            ['runs' => '0'],
            'Option --runs must be at least 1, got 0.',
        ];
        yield 'invalid warm-up count' => [
            ['warmups' => '-1'],
            'Option --warmups must be at least 0, got -1.',
        ];
        yield 'non-integer worker count' => [
            ['workers' => 'many'],
            'Option --workers must be an integer, got "many".',
        ];
        yield 'repeated shape' => [
            ['shape' => ['many-fast', 'mixed']],
            'Specify option --shape exactly once with a value.',
        ];
        yield 'unknown format' => [
            ['format' => 'csv'],
            'Option --format must be "table" or "json", got "csv".',
        ];
        yield 'excessive pause' => [
            ['pause-ms' => '60001'],
            'Option --pause-ms must be at most 60000, got 60001.',
        ];
    }

    #[Test]
    #[DataSet('specializedShapes')]
    public function generatesSpecializedRunnerShapes(string $shape, string $relativeFile, string $expectedText): void
    {
        $project = $this->tempDirectory->path() . '/benchmark-' . $shape;

        Expect::that(\benchmarkGenerateShape($shape, 1, $project))
            ->because('each specialized benchmark shape MUST contain tests')
            ->toBeGreaterThan(0);
        Expect::that((string) \file_get_contents($project . '/' . $relativeFile))
            ->toContain($expectedText);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function specializedShapes(): iterable
    {
        yield 'many isolated tests' => ['many-isolated', 'tests/gl/ManyIsolated0000Test.php', '#[Isolated]'];
        yield 'recycle after one test' => ['recycle-one', 'greenlight.php', 'recycleAfterTests: 1'];
        yield 'resource constrained work' => ['resource-constrained', 'greenlight.php', "resourceLimit('database')"];
        yield 'skewed worker bootstrap' => ['skewed-bootstrap', 'greenlight.php', 'BenchmarkBootstrapPlugin'];
        yield 'chatty worker diagnostics' => ['chatty-diagnostics', 'tests/gl/ChattyDiagnostics0000Test.php', 'Benchmark diagnostic.'];
        yield 'coverage-heavy assignments' => ['coverage-heavy', 'greenlight.php', 'CoverageBuilder'];
        yield 'single giant data set' => ['giant-dataset', 'tests/gl/GiantTest.php', 'final class GiantTest'];
        yield 'Pest giant data set' => ['giant-dataset', 'tests/pest/GiantTest.php', "test('handles'"];
    }

    #[Test]
    public function comparisonConfigurationsIncludeNativePestRuns(): void
    {
        $configurations = \benchmarkConfigurations('many-fast', '/tmp/project', '/tmp/root', 4, true);

        Expect::that($configurations)->toHaveKey('pest');
        Expect::that($configurations)->toHaveKey('pest-parallel');
        Expect::that($configurations['phpunit']['command'])->toContain('--cache-directory=.benchmark-cache/phpunit');
        Expect::that($configurations['paratest']['command'])->toContain('--cache-directory=.benchmark-cache/paratest');
        Expect::that($configurations['pest']['command'])->toContain('--configuration=pest.xml');
        Expect::that($configurations['pest']['command'])->toContain('--cache-directory=.benchmark-cache/pest');
        Expect::that($configurations['pest-parallel']['command'])->toContain('--parallel --processes=4');
        Expect::that($configurations['pest-parallel']['command'])->toContain('--cache-directory=.benchmark-cache/pest-parallel');
    }

    #[Test]
    public function installedComparisonPackageVersionsAreCompleteAndSorted(): void
    {
        $project = $this->tempDirectory->subdirectory('installed-packages');
        $composer = $this->tempDirectory->subdirectory('installed-packages/vendor/composer');
        \file_put_contents($composer . '/installed.php', <<<'PHP'
            <?php

            return [
                'versions' => [
                    '__root__' => ['pretty_version' => 'dev-main'],
                    'vendor/zeta' => ['pretty_version' => '2.0.0'],
                    'vendor/alpha' => ['pretty_version' => '1.0.0'],
                ],
            ];
            PHP);

        Expect::that(\benchmarkInstalledPackages($project))->toBe([
            'vendor/alpha' => '1.0.0',
            'vendor/zeta' => '2.0.0',
        ]);
    }
}
