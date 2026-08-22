<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\ReportGenerationFailed;

final class ReportGenerationFailedTest
{
    #[Test]
    public function reportsWriteFailureExactly(): void
    {
        Expect::that(ReportGenerationFailed::writeFailed()->getMessage())
            ->toBe('Greenlight did not write reporter output to the stream.');
    }

    #[Test]
    public function reportsAnUnmappedEventExactly(): void
    {
        Expect::that(ReportGenerationFailed::unmappedEvent(self::class)->getMessage())
            ->toBe(\sprintf(
                'Event "%s" has no stable tag. Add the event to the tag map before Greenlight writes it.',
                self::class,
            ));
    }

    #[Test]
    public function reportsTheMissingXmlWriterExtensionExactly(): void
    {
        Expect::that(ReportGenerationFailed::xmlUnavailable()->getMessage())
            ->because('JUnit output names the required PHP extension')
            ->toBe('The XMLWriter extension is required for JUnit output. Enable ext-xmlwriter.');
    }
}
