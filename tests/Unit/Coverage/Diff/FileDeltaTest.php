<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Diff;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Diff\FileDelta;

use function Greenlight\expect;

final readonly class FileDeltaTest
{
    #[Test]
    public function zeroStringFilePathRetainsDeltaDetails(): void
    {
        $delta = new FileDelta('0', 75.0, 50.0, 1, 2);

        expect($delta->file)
            ->because('a zero-string coverage delta file path is not empty')
            ->toBe('0');
        expect($delta->uncoveredLinesChanged())
            ->because('a coverage delta MUST compare its uncovered-line counts')
            ->toBeTrue();
        expect($delta->delta())
            ->because('a coverage delta MUST calculate the percentage change')
            ->toBe(-25.0);
    }

    #[Test]
    public function anEmptyFilePathIsRejected(): void
    {
        expect()->calling(static fn(): FileDelta => new FileDelta('', 100.0, 50.0))
            ->because('coverage deltas MUST identify a file')
            ->toThrow(\InvalidArgumentException::class, message: 'Use a non-empty coverage delta file path.');
    }

    #[Test]
    public function moreUncoveredLinesAtTheSamePercentageIsARegression(): void
    {
        expect(new FileDelta('/src/A.php', 50.0, 50.0, 1, 2)->regressed())
            ->because('an increased uncovered-line count MUST be a regression')
            ->toBeTrue();
    }

    #[Test]
    public function aDifferenceBelowTheDisplayedPrecisionIsNotARegression(): void
    {
        expect(new FileDelta('/src/A.php', 66.671, 66.669, 1, 1)->regressed())
            ->because('percentages MUST compare at the displayed precision')
            ->toBeFalse();
    }

    #[Test]
    public function anAddedFileWithUncoveredLinesRegresses(): void
    {
        expect(new FileDelta('/src/A.php', null, 0.0, null, 5)->regressed())
            ->because('an added file with uncovered lines MUST regress')
            ->toBeTrue();
        expect(new FileDelta('/src/A.php', null, 100.0, null, 0)->regressed())
            ->because('a fully covered added file MUST NOT regress')
            ->toBeFalse();
    }

    #[Test]
    public function aRemovedFileCannotRegress(): void
    {
        expect(new FileDelta('/src/A.php', 100.0, null, 0)->regressed())
            ->because('a removed file MUST NOT regress')
            ->toBeFalse();
    }
}
