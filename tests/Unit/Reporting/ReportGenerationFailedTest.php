<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\ReportGenerationFailed;

final class ReportGenerationFailedTest
{
    #[Test]
    public function createsACustomReporterFailure(): void
    {
        $previous = new \RuntimeException('Template error.');
        $failure = ReportGenerationFailed::because(' the template is invalid ', $previous);

        Expect::that($failure->getMessage())
            ->toBe('The reporter did not generate output because the template is invalid.');
        Expect::that($failure->getPrevious())
            ->toBe($previous);
    }

    #[Test]
    public function rejectsAnEmptyCustomReporterFailureReason(): void
    {
        Expect::that(static fn() => ReportGenerationFailed::because(" \n\t "))
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Report generation failure reason cannot be empty.',
            );
    }

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
