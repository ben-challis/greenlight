<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\CoverageBuilder;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Expect\Expect;

final class CoverageBuilderTest
{
    #[Test]
    public function anEmptyIncludePathIsRejected(): void
    {
        Expect::that(static function (): void {
            new CoverageBuilder()->include(''); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        })
            ->because('a coverage include path identifies source to instrument')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Coverage include paths cannot be empty.',
            );
    }

    #[Test]
    public function aRejectedIncludeCallDoesNotPartiallyChangeTheBuilder(): void
    {
        $builder = new CoverageBuilder()->include('src');

        Expect::that(static fn(): CoverageBuilder => $builder->include('app', '')) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('a rejected include call does not partially change the builder')
            ->toThrow(InvalidConfiguration::class);

        Expect::that($builder->toConfiguration()->includePaths)
            ->because('a rejected include call retains the prior paths')
            ->toBe(['src']);
    }

    #[Test]
    public function aNullByteIncludePathIsRejected(): void
    {
        Expect::that(static fn(): CoverageBuilder => new CoverageBuilder()->include("src\0hidden"))
            ->because('coverage include paths MUST be valid filesystem inputs')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Coverage include paths cannot contain a null byte.',
            );
    }

    #[Test]
    public function anEmptyDriverNameIsRejected(): void
    {
        Expect::that(static function (): void {
            new CoverageBuilder()->driver(''); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        })
            ->because('a coverage driver needs a selectable name')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Coverage driver cannot be empty.',
            );
    }

    #[Test]
    #[DataSet('invalidExports')]
    public function aCoverageExportNeedsAFormatAndTarget(string $format, string $target): void
    {
        Expect::that(static function () use ($format, $target): void {
            // Reflection bypasses the static non-empty-string types and
            // exercises the runtime guard.
            new \ReflectionMethod(CoverageBuilder::class, 'export')
                ->invoke(new CoverageBuilder(), $format, $target);
        })
            ->because('a coverage export needs a format and target')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Coverage exports need a non-empty format and target.',
            );
    }

    #[Test]
    public function preservesZeroStringsAcrossTheBuilderState(): void
    {
        $configuration = new CoverageBuilder()
            ->include('0')
            ->driver('0')
            ->export('json', '0')
            ->toConfiguration();

        Expect::that($configuration->includePaths)
            ->because('a zero-string coverage include path is not empty')
            ->toBe(['0']);
        Expect::that($configuration->driver)
            ->because('a zero-string coverage driver is not empty')
            ->toBe('0');
        Expect::that($configuration->exports[0]->format)
            ->toBe('json');
        Expect::that($configuration->exports[0]->target)
            ->because('a zero-string coverage export target is not empty')
            ->toBe('0');
    }

    #[Test]
    public function buildsCoverageGateSettings(): void
    {
        $configuration = new CoverageBuilder()
            ->minimumPercentage(95.25)
            ->maximumUncoveredLines(0)
            ->requireDriver()
            ->toConfiguration();

        Expect::that($configuration->minimumPercentage)
            ->because('the minimum percentage MUST remain available to the run')
            ->toBe(95.25);
        Expect::that($configuration->maximumUncoveredLines)
            ->because('zero is a valid maximum uncovered-line count')
            ->toBe(0);
        Expect::that($configuration->requireDriver)
            ->because('the run MUST retain the coverage-driver requirement')
            ->toBeTrue();
    }

    #[Test]
    #[DataSet('invalidMinimumPercentages')]
    public function rejectsInvalidMinimumPercentages(float $percentage, string $message): void
    {
        Expect::that(static fn(): CoverageBuilder => new CoverageBuilder()->minimumPercentage($percentage))
            ->because('a coverage percentage gate MUST have an exact supported boundary')
            ->toThrow(InvalidConfiguration::class, message: $message);
    }

    #[Test]
    public function rejectsANegativeMaximumUncoveredLineCount(): void
    {
        Expect::that(static function (): void {
            new CoverageBuilder()->maximumUncoveredLines(-1); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        })
            ->because('an uncovered-line maximum cannot be negative')
            ->toThrow(InvalidConfiguration::class, message: 'Maximum uncovered lines cannot be negative.');
    }

    /** @return iterable<string, array{float, non-empty-string}> */
    public static function invalidMinimumPercentages(): iterable
    {
        yield 'negative' => [-0.01, 'Minimum coverage percentage must be from 0 through 100.'];
        yield 'above 100' => [100.01, 'Minimum coverage percentage must be from 0 through 100.'];
        yield 'too precise' => [99.999, 'Minimum coverage percentage can have at most two decimal places.'];
        yield 'not finite' => [\INF, 'Minimum coverage percentage must be from 0 through 100.'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidExports(): iterable
    {
        yield 'empty format' => ['', 'coverage.json'];
        yield 'empty target' => ['json', ''];
    }
}
