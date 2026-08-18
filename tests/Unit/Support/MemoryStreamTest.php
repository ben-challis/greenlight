<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\MemoryStream;

final readonly class MemoryStreamTest
{
    #[Test]
    public function opensAtTheStartOfExactContentAndClosesIdempotently(): void
    {
        $stream = MemoryStream::open('initial content');

        Expect::that(\stream_get_contents($stream))
            ->because('the shared stream MUST expose all initial content from its start')
            ->toBe('initial content');

        MemoryStream::close($stream, $stream);

        Expect::that(\is_resource($stream))
            ->because('the shared close operation MUST tolerate an already closed stream')
            ->toBeFalse();
    }
}
