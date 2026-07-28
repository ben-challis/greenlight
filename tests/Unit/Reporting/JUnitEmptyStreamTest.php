<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JUnitReporter;

final class JUnitEmptyStreamTest
{
    #[Test]
    public function finishingWithoutEventsWritesAZeroTestDocument(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);

        $reporter->finish();

        Expect::that($output->buffer())
            ->because('an empty test run remains a complete JUnit document')
            ->toBe(
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<testsuites name=\"greenlight\" tests=\"0\" failures=\"0\" errors=\"0\" skipped=\"0\" time=\"0.000000\">\n"
                . "</testsuites>\n",
            );
    }
}
