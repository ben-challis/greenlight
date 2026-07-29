<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\MemorySize;
use Greenlight\Expect\Expect;

final readonly class MemorySizeRoundTripTest
{
    #[Test]
    public function plainByteFormatCanBeParsed(): void
    {
        $bytes = 1000;

        Expect::that(MemorySize::parseToBytes(MemorySize::format($bytes)))
            ->because('a formatted plain-byte size MUST parse to its original value')
            ->toBe($bytes);
    }
}
