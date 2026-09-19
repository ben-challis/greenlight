<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tools;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

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
            'output' => '/tmp/benchmark.json',
            'with-comparisons' => false,
        ]);

        expect($options)->toBe([
            'shapes' => ['many-isolated'],
            'scale' => 2,
            'workers' => 3,
            'warmups' => 2,
            'runs' => 5,
            'seed' => -17,
            'pauseMs' => 250,
            'format' => 'json',
            'output' => '/tmp/benchmark.json',
            'withComparisons' => true,
        ]);
    }

    #[Test]
    public function scheduleIsReproducibleAndAlternatesEachPairOfRounds(): void
    {
        $configurationIds = ['parallel', 'one', 'phpunit', 'paratest', 'pest', 'pest-parallel'];
        $schedule = \benchmarkSchedule($configurationIds, 12, 731, 'many-fast:sample');

        expect($schedule)->because('the same seed MUST reproduce the configuration order')
            ->toBe(\benchmarkSchedule($configurationIds, 12, 731, 'many-fast:sample'));
        expect($schedule[1])->because('the second round MUST reverse the first round')
            ->toBe(\array_reverse($schedule[0]));
        expect($schedule[3])->because('the fourth round MUST reverse the third round')
            ->toBe(\array_reverse($schedule[2]));

        foreach ($schedule as $order) {
            \sort($order);
            $expected = $configurationIds;
            \sort($expected);

            expect($order)->because('each round MUST contain each configuration once')->toBe($expected);
        }

        foreach ($configurationIds as $configurationId) {
            $configurationCount = \count($configurationIds);

            for ($position = 0; $position < $configurationCount; ++$position) {
                $positionCount = \count(\array_filter(
                    $schedule,
                    static fn(array $order): bool => $order[$position] === $configurationId,
                ));
                expect($positionCount)
                    ->because('the default sample count MUST put each configuration in each position twice')
                    ->toBe(2);
            }
        }
    }

    #[Test]
    public function distributionReportsRobustLocationAndVariationStatistics(): void
    {
        expect(\benchmarkDistribution([9.0, 1.0, 5.0, 3.0]))->toBe([
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
        expect()->calling(static fn() => \benchmarkParseOptions($options))
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{array<string, string|array<mixed>|false>, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'unknown shape' => [
            ['shape' => 'unknown'],
            'Unknown benchmark shape "unknown". Use one of: minimal, many-fast, few-slow, cpu-bound, giant-dataset, mixed, many-isolated, resource-constrained, skewed-bootstrap, chatty-diagnostics, coverage-heavy.',
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
        yield 'empty output path' => [
            ['output' => ''],
            'Option --output must specify a JSON file path.',
        ];
    }

    #[Test]
    #[DataSet('specializedShapes')]
    public function generatesSpecializedRunnerShapes(string $shape, string $relativeFile, string $expectedText): void
    {
        $project = $this->tempDirectory->path() . '/benchmark-' . $shape;

        expect(\benchmarkGenerateShape($shape, 1, $project))
            ->because('each specialized benchmark shape MUST contain tests')
            ->toBeGreaterThan(0);
        expect((string) \file_get_contents($project . '/' . $relativeFile))
            ->toContain($expectedText);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function specializedShapes(): iterable
    {
        yield 'many isolated tests' => ['many-isolated', 'tests/gl/ManyIsolated0000Test.php', '#[Isolated]'];
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

        expect($configurations)->toHaveKey('pest');
        expect($configurations)->toHaveKey('pest-parallel');
        expect($configurations['phpunit']['command'])->toContain('--cache-directory=.benchmark-cache/phpunit');
        expect($configurations['paratest']['command'])->toContain('--cache-directory=.benchmark-cache/paratest');
        expect($configurations['pest']['command'])->toContain('--configuration=pest.xml');
        expect($configurations['pest']['command'])->toContain('--cache-directory=.benchmark-cache/pest');
        expect($configurations['pest-parallel']['command'])->toContain('--parallel --processes=4');
        expect($configurations['pest-parallel']['command'])->toContain('--cache-directory=.benchmark-cache/pest-parallel');
    }

    #[Test]
    public function oneWorkerDoesNotCreateDuplicateGreenlightMeasurements(): void
    {
        $configurations = \benchmarkConfigurations('many-isolated', '/tmp/project', '/tmp/root', 1, false);

        expect(\array_keys($configurations))->toBe(['greenlight-one']);
        expect($configurations['greenlight-one']['executionMode'])->toBe('in-process');
        expect(\benchmarkConfigurations('many-isolated', '/tmp/project', '/tmp/root', 4, false)['greenlight-parallel']['executionMode'])
            ->toBe('fresh-process-per-test');
    }

    #[Test]
    public function detectsUnbalancedSamplePositions(): void
    {
        $ids = ['one', 'four', 'phpunit', 'paratest', 'pest', 'pest-parallel'];

        expect(\benchmarkScheduleIsBalanced(\benchmarkSchedule($ids, 12, 731, 'sample')))->toBeTrue();
        expect(\benchmarkScheduleIsBalanced(\benchmarkSchedule($ids, 5, 731, 'sample')))->toBeFalse();
        expect(\benchmarkScheduleIsBalanced(\benchmarkSchedule(['one'], 1, 731, 'sample')))->toBeTrue();
        expect(\benchmarkScheduleIsBalanced([]))->toBeFalse();
    }

    #[Test]
    public function outputFileCannotReplaceAnExistingResult(): void
    {
        $path = $this->tempDirectory->path() . '/existing.json';
        \file_put_contents($path, 'previous result');

        expect()->calling(static fn() => \benchmarkOpenOutput($path))->toThrow(\RuntimeException::class);
        expect(\file_get_contents($path))->toBe('previous result');
    }

    #[Test]
    public function jsonFileRetainsSamplesAndCaveatsAlongsideTheTable(): void
    {
        $path = $this->tempDirectory->path() . '/report.json';
        $output = \benchmarkOpenOutput($path);
        $options = \benchmarkParseOptions(['shape' => 'many-isolated', 'output' => $path, 'runs' => '5']);
        $rows = [];

        foreach (\benchmarkConfigurations('many-isolated', '/tmp/project', '/tmp/root', 4, false) as $id => $configuration) {
            $rows[] = [
                'shape' => 'many-isolated',
                'tests' => 40,
                'configurationId' => $id,
                ...$configuration,
                'samplesSeconds' => [0.1, 0.2, 0.3, 0.4, 0.5],
                ...\benchmarkDistribution([0.1, 0.2, 0.3, 0.4, 0.5]),
            ];
        }

        \ob_start();

        try {
            \benchmarkReport($options, $rows, \dirname(__DIR__, 3), [], $output);
            $table = (string) \ob_get_contents();
        } finally {
            \ob_end_clean();

            if (\is_resource($output)) {
                \fclose($output);
            }
        }

        $json = (string) \file_get_contents($path);
        $report = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($report)) {
            throw new \RuntimeException('The benchmark report must decode to an array.');
        }

        expect($report['results'] ?? null)->toBe($rows);
        expect($json)->toContain('"schemaVersion": 1');
        expect($json)->toContain('"schedules":');
        expect($json)->toContain('unbalanced sample order');
        expect($json)->toContain('does not provide process isolation');
        expect($table)->toContain('execution mode');
        expect($table)->toContain('fresh-process-per-test');
        expect($table)->toContain('JSON report: ' . $path);
    }

    #[Test]
    #[DataSet('newCommonShapes')]
    public function newCommonShapesExecuteAllGeneratedTests(string $shape, int $tests): void
    {
        $project = $this->tempDirectory->path() . '/benchmark-' . $shape;
        expect(\benchmarkGenerateShape($shape, 1, $project))->toBe($tests);
        expect(\benchmarkHasComparisonFixture($shape))->toBeTrue();

        foreach (\benchmarkConfigurations($shape, $project, \dirname(__DIR__, 3), 2, false) as $id => $configuration) {
            \benchmarkVerifyConfiguration($configuration['command'], $tests, $project, $id);
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function newCommonShapes(): iterable
    {
        yield 'minimal suite' => ['minimal', 1];
        yield 'CPU work' => ['cpu-bound', 8];
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

        expect(\benchmarkInstalledPackages($project))->toBe([
            'vendor/alpha' => '1.0.0',
            'vendor/zeta' => '2.0.0',
        ]);
    }
}
