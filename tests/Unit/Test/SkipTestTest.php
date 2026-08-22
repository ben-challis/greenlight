<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\SkipTest;

final class SkipTestTest
{
    #[Test]
    public function anEmptyReasonIsRejected(): void
    {
        Expect::that(static fn(): SkipTest => new SkipTest(''))
            ->because('skip reasons cannot be empty')
            ->toThrow(\InvalidArgumentException::class, message: 'Skip reasons cannot be empty.');
    }

    #[Test]
    public function aZeroStringReasonIsPreserved(): void
    {
        $skip = new SkipTest('0');

        Expect::that($skip->reason)
            ->because('a skip signal MUST preserve a zero-string reason')
            ->toBe('0');
        Expect::that($skip->getMessage())
            ->toBe('0');
    }
}
