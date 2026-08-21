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
            'with-phpunit' => false,
        ]);

        Expect::that($options)->toBe([
            'shapes' => ['many-isolated'],
            'scale' => 2,
            'workers' => 3,
            'warmups' => 2,
            'runs' => 5,
            'seed' => -17,
            'withPhpunit' => true,
        ]);
    }

    #[Test]
    public function scheduleIsReproducibleAndAlternatesEachPairOfRounds(): void
    {
        $configurationIds = ['parallel', 'one', 'phpunit', 'paratest'];
        $schedule = \benchmarkSchedule($configurationIds, 4, 731, 'many-fast:sample');

        Expect::that($schedule)->because('the same seed MUST reproduce the configuration order')
            ->toBe(\benchmarkSchedule($configurationIds, 4, 731, 'many-fast:sample'));
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
    }

    #[Test]
    public function distributionUsesTheConventionalMedianAndReportsTheObservedRange(): void
    {
        Expect::that(\benchmarkDistribution([9.0, 1.0, 5.0, 3.0]))->toBe([
            'minimum' => 1.0,
            'median' => 4.0,
            'maximum' => 9.0,
            'spreadPercent' => 200.0,
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
    }
}
