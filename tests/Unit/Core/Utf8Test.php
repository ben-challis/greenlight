<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Wire\Utf8;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final class Utf8Test
{
    #[Test]
    public function validUtf8PassesThroughUntouched(): void
    {
        Expect::that(Utf8::scrub('plain'))->toBe('plain');
        Expect::that(Utf8::scrub('naïve ✓'))->toBe('naïve ✓');
        Expect::that(Utf8::scrub(''))->toBe('');
    }

    #[Test]
    public function invalidBytesAreSubstituted(): void
    {
        $scrubbed = Utf8::scrub("bad \xB1\x31 bytes");

        Expect::that($scrubbed)->toMatch('//u');
        Expect::that($scrubbed)->toContain('bad');
        Expect::that($scrubbed)->toContain('1 bytes');
    }

    #[Test]
    public function throwableWithBinaryMessageSurvivesTheWire(): void
    {
        $detail = ThrowableDetail::fromThrowable(new \RuntimeException("query failed: \xB1\x31\xFF"));
        $restored = ThrowableDetail::fromWire(JsonWire::roundTrip($detail->toWire()));

        Expect::that($restored->class)->toBe(\RuntimeException::class);
        Expect::that($restored->message)->toContain('query failed');
    }
}
