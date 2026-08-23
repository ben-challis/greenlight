<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Execution\Worker\CapturedStreamFilter;
use Greenlight\Expect\Expect;

final readonly class CapturedStreamFilterTest
{
    #[Test]
    public function missingBufferParametersRejectTheWrite(): void
    {
        $name = 'greenlight.test.missing-buffer';
        \stream_filter_register($name, CapturedStreamFilter::class);
        $stream = \fopen('php://temp', 'w+');

        if (!\is_resource($stream)) {
            throw new \RuntimeException('The test stream did not open.');
        }

        $filter = \stream_filter_append($stream, $name, \STREAM_FILTER_WRITE);

        if (!\is_resource($filter)) {
            throw new \RuntimeException('The test filter did not attach.');
        }

        try {
            Expect::that(@\fwrite($stream, 'discarded'))
                ->because('a capture filter without its buffer MUST reject the write')
                ->toBeFalse();
        } finally {
            @\stream_filter_remove($filter);
            \fclose($stream);
        }
    }
}
