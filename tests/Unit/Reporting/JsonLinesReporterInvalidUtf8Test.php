<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\SuiteStarted;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JsonLinesReporter;

final class JsonLinesReporterInvalidUtf8Test
{
    #[Test]
    public function invalidUtf8InEventFieldsIsSubstituted(): void
    {
        $output = new BufferOutput();
        $reporter = new JsonLinesReporter($output);

        $reporter->onEvent(new SuiteStarted("bad \xFF suite", 1.0));

        Expect::that(\json_decode($output->buffer(), true, flags: \JSON_THROW_ON_ERROR))
            ->because('JSONL event fields remain valid UTF-8')
            ->toBe([
                'v' => 2,
                'event' => 'suite-started',
                'data' => [
                    'suite' => "bad \u{FFFD} suite",
                    'occurredAt' => 1,
                ],
            ]);
    }
}
