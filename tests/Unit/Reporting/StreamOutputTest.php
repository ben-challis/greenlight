<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Output\StreamOutput;
use Greenlight\Reporting\ReportingError;

final class StreamOutputTest
{
    #[Test]
    public function writesAccumulateOnTheStream(): void
    {
        $stream = \fopen('php://memory', 'r+');

        if ($stream === false) {
            throw new \RuntimeException('Could not open an in-memory stream.');
        }

        $output = new StreamOutput($stream);
        $output->write('first ');
        $output->write('second');

        \rewind($stream);

        Expect::that(\stream_get_contents($stream))->because('writes accumulate on the stream')->toBe('first second');

        \fclose($stream);
    }

    #[Test]
    public function aStreamWriteFailureBecomesAReportingError(): void
    {
        $stream = \fopen('php://memory', 'r');

        if ($stream === false) {
            throw new \RuntimeException('Could not open a read-only in-memory stream.');
        }

        try {
            $output = new StreamOutput($stream);

            Expect::that(static function () use ($output): void {
                $output->write('cannot be written');
            })
                ->because('a stream write failure becomes a reporting error')
                ->toThrow(
                    ReportingError::class,
                    message: 'Greenlight did not write reporter output to the stream.',
                );
        } finally {
            \fclose($stream);
        }
    }
}
