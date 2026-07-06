<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Wire\Utf8;
use Greenlight\Tests\Support\Check;

final class Utf8Test
{
    #[Test]
    public function validUtf8PassesThroughUntouched(): void
    {
        Check::same('plain', Utf8::scrub('plain'), 'ascii');
        Check::same('naïve ✓', Utf8::scrub('naïve ✓'), 'multibyte');
        Check::same('', Utf8::scrub(''), 'empty string');
    }

    #[Test]
    public function invalidBytesAreSubstituted(): void
    {
        $scrubbed = Utf8::scrub("bad \xB1\x31 bytes");

        Check::same(1, \preg_match('//u', $scrubbed), 'scrubbed string to be valid UTF-8');
        Check::true(\str_contains($scrubbed, 'bad'), 'valid parts to survive');
        Check::true(\str_contains($scrubbed, '1 bytes'), 'valid tail to survive');
    }

    #[Test]
    public function throwableWithBinaryMessageSurvivesTheWire(): void
    {
        $detail = ThrowableDetail::fromThrowable(new \RuntimeException("query failed: \xB1\x31\xFF"));
        $restored = ThrowableDetail::fromWire(Check::jsonRoundTrip($detail->toWire()));

        Check::same(\RuntimeException::class, $restored->class, 'class');
        Check::true(\str_contains($restored->message, 'query failed'), 'readable part of the message to survive');
    }
}
