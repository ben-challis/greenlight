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
            new CoverageBuilder()->include('');
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

        Expect::that(static fn(): CoverageBuilder => $builder->include('app', ''))
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
            new CoverageBuilder()->driver('');
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
            new CoverageBuilder()->export($format, $target);
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
            ->export('0', '0')
            ->toConfiguration();

        Expect::that($configuration->includePaths)
            ->because('a zero-string coverage include path is not empty')
            ->toBe(['0']);
        Expect::that($configuration->driver)
            ->because('a zero-string coverage driver is not empty')
            ->toBe('0');
        Expect::that($configuration->exports[0]->format)
            ->because('a zero-string coverage export format is not empty')
            ->toBe('0');
        Expect::that($configuration->exports[0]->target)
            ->because('a zero-string coverage export target is not empty')
            ->toBe('0');
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
