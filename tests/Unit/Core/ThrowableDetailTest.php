<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

final class ThrowableDetailTest
{
    #[Test]
    public function deepTracesAreBoundedWithATruncationMarker(): void
    {
        $threw = null;

        try {
            $this->throwAtDepth(40);
        } catch (\RuntimeException $exception) {
            $threw = $exception;
        }

        if (!$threw instanceof \RuntimeException) {
            Fail::because('Expected the recursive helper to throw.');
        }

        $detail = ThrowableDetail::fromThrowable($threw);

        Expect::that($detail->stackFrames)
            ->because('deep throwable traces are bounded with a truncation marker')
            ->toHaveCount(33)
            ->and($detail->stackFrames[32])
            ->toBe('... (trace truncated)');
    }

    private function throwAtDepth(int $remaining): void
    {
        if ($remaining === 0) {
            throw new \RuntimeException('bottom');
        }

        $this->throwAtDepth($remaining - 1);
    }
}
