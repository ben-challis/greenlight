<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Reporting\Style;
use Greenlight\Reporting\SummaryFormat;
use Greenlight\Result\ResultSummary;

use function Greenlight\expect;

final class SummaryFormatZeroPassedColorTest
{
    #[Test]
    public function aZeroPassedCountRemainsUnstyled(): void
    {
        $formatted = SummaryFormat::tests(
            new ResultSummary(failed: 1),
            0,
            new Style(ansi: true),
        );

        expect($formatted)
            ->because('a zero passed count MUST remain plain while failures use the error style')
            ->toBe("1 test, 0 passed, \x1b[31m1 failed\x1b[0m, 0 expectations");
    }
}
