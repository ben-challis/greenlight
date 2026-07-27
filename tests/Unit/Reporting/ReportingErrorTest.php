<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\ReportingError;

final class ReportingErrorTest
{
    #[Test]
    public function reportsWriteFailureExactly(): void
    {
        Expect::that(ReportingError::writeFailed()->getMessage())
            ->toBe('Greenlight did not write reporter output to the stream.');
    }

    #[Test]
    public function reportsAnUnmappedEventExactly(): void
    {
        Expect::that(ReportingError::unmappedEvent(self::class)->getMessage())
            ->toBe(\sprintf(
                'Event "%s" has no stable tag. Add the event to the tag map before Greenlight writes it.',
                self::class,
            ));
    }
}
