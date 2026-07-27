<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Expect\Expect;

final class ThrowableDetailTest
{
    #[Test]
    public function deepStackTracesAreBoundedWithATruncationMarker(): void
    {
        $detail = ThrowableDetail::fromThrowable($this->deepThrowable(40));

        Expect::that($detail->stackFrames)
            ->because('a throwable wire payload has a bounded stack trace')
            ->toHaveCount(33)
            ->and($detail->stackFrames[32])
            ->toBe('... (trace truncated)');
    }

    private function deepThrowable(int $remaining): \Throwable
    {
        if ($remaining === 0) {
            return new \RuntimeException('deep failure');
        }

        return $this->deepThrowable($remaining - 1);
    }
}
