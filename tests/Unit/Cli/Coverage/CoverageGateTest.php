<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Coverage\CoverageGate;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;

use function Greenlight\expect;

final class CoverageGateTest
{
    #[Test]
    public function percentageUsesTwoDecimalHalfUpRoundingAndLimitsAreInclusive(): void
    {
        $coverage = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [3])]);
        $configuration = new CoverageConfiguration([], null, [], 66.67, 1);

        expect(CoverageGate::failures($configuration, $coverage))
            ->because('66.666 percent rounds to the inclusive 66.67-percent boundary')
            ->toBe([]);
    }

    #[Test]
    public function reportsEachFailedGateInStableOrder(): void
    {
        $coverage = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [3])]);
        $configuration = new CoverageConfiguration([], null, [], 66.68, 0);

        expect(CoverageGate::failures($configuration, $coverage))
            ->because('all failed gates MUST be visible to the user')
            ->toBe([
                'Coverage gate failed: 66.67% is less than the minimum 66.68%.',
                'Coverage gate failed: 1 uncovered line exceeds the maximum 0.',
            ]);
    }

    #[Test]
    #[DataSet('gates')]
    public function aConfiguredGateFailsWhenNoLineIsExecutable(CoverageConfiguration $configuration): void
    {
        expect(CoverageGate::failures($configuration, CoverageMap::empty()))
            ->because('an empty coverage map MUST NOT pass a configured gate')
            ->toBe(['Coverage gate failed: the coverage contains no executable lines. Check the coverage include paths.']);
    }

    #[Test]
    public function anEmptyMapWithoutAGateHasNoFailure(): void
    {
        expect(CoverageGate::failures(new CoverageConfiguration([], null, []), CoverageMap::empty()))
            ->because('coverage without a gate MUST NOT fail')
            ->toBe([]);
    }

    /** @return iterable<string, array{CoverageConfiguration}> */
    public static function gates(): iterable
    {
        yield 'minimum percentage' => [new CoverageConfiguration([], null, [], 100.0)];
        yield 'maximum uncovered lines' => [new CoverageConfiguration([], null, [], maximumUncoveredLines: 0)];
    }
}
