<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Output\StreamOutput;

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

        new Expect()->that(\stream_get_contents($stream))->toBe('first second');

        \fclose($stream);
    }
}
