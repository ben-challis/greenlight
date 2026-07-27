<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

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
    public function aCoverageExportNeedsAFormatAndTarget(): void
    {
        foreach ([['', 'coverage.json'], ['json', '']] as [$format, $target]) {
            Expect::that(static function () use ($format, $target): void {
                new CoverageBuilder()->export($format, $target);
            })
                ->because('a coverage export needs a format and target')
                ->toThrow(
                    InvalidConfiguration::class,
                    message: 'Coverage exports need a non-empty format and target.',
                );
        }
    }
}
