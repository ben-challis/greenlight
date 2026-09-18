<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Test\SkipTest;

use function Greenlight\expect;

final class SkipTestTest
{
    #[Test]
    public function anEmptyReasonIsRejected(): void
    {
        expect()->calling(static fn(): SkipTest => new SkipTest(''))
            ->because('skip reasons cannot be empty')
            ->toThrow(\InvalidArgumentException::class, message: 'Skip reasons cannot be empty.');
    }

    #[Test]
    public function aZeroStringReasonIsPreserved(): void
    {
        $skip = new SkipTest('0');

        expect($skip->reason)
            ->because('a skip signal MUST preserve a zero-string reason')
            ->toBe('0');
        expect($skip->getMessage())
            ->toBe('0');
    }
}
