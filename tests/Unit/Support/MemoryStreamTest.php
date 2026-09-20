<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Tests\Support\MemoryStream;

use function Greenlight\expect;

final readonly class MemoryStreamTest
{
    #[Test]
    public function opensAtTheStartOfExactContentAndClosesIdempotently(): void
    {
        $stream = MemoryStream::open('initial content');

        expect(\stream_get_contents($stream))
            ->because('the shared stream MUST expose all initial content from its start')
            ->toBe('initial content');

        MemoryStream::close($stream, $stream);

        expect(\is_resource($stream))
            ->because('the shared close operation MUST tolerate an already closed stream')
            ->toBeFalse();
    }
}
