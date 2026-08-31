<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\TestClassStarted;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JsonLinesReporter;

final class JsonLinesReporterInvalidUtf8Test
{
    #[Test]
    public function invalidUtf8InEventFieldsIsSubstituted(): void
    {
        $output = new BufferOutput();
        $reporter = new JsonLinesReporter($output);

        $reporter->onEvent(new TestClassStarted("bad \xFF class", 1.0));

        Expect::that(\json_decode($output->buffer(), true, flags: \JSON_THROW_ON_ERROR))
            ->because('JSONL event fields remain valid UTF-8')
            ->toBe([
                'v' => 1,
                'event' => 'class-started',
                'data' => [
                    'class' => "bad \u{FFFD} class",
                    'occurredAt' => 1,
                    'workerId' => '',
                ],
            ]);
    }
}
