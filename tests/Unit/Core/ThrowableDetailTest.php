<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Expect\Expect;

final class ThrowableDetailTest
{
    #[Test]
    public function deepTracesAreBoundedWithATruncationMarker(): void
    {
        try {
            $this->throwAtDepth(40);
        } catch (\RuntimeException $threw) {
            $detail = ThrowableDetail::fromThrowable($threw);

            Expect::that($detail->stackFrames)
                ->because('deep throwable traces are bounded with a truncation marker')
                ->toHaveCount(33)
                ->and($detail->stackFrames[32])
                ->toBe('... (trace truncated)');
        }
    }

    private function throwAtDepth(int $remaining): never
    {
        if ($remaining === 0) {
            throw new \RuntimeException('bottom');
        }

        $this->throwAtDepth($remaining - 1);
    }
}
