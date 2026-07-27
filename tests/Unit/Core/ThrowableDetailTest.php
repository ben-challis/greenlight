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
        $capture = new class {
            public ?\RuntimeException $exception = null;
        };

        Expect::that(function () use ($capture): void {
            try {
                $this->throwAtDepth(40);
            } catch (\RuntimeException $exception) {
                $capture->exception = $exception;

                throw $exception;
            }
        })
            ->because('the recursive helper MUST throw at its terminal depth')
            ->toThrow(\RuntimeException::class, message: 'bottom');

        $threw = $capture->exception;

        if (!$threw instanceof \RuntimeException) {
            Fail::because('Expected to capture the recursive helper exception.');
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
