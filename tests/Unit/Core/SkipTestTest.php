<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;

final class SkipTestTest
{
    #[Test]
    public function anEmptyReasonIsRejected(): void
    {
        Expect::that(static fn(): SkipTest => new SkipTest(''))
            ->because('skip reasons cannot be empty')
            ->toThrow(\InvalidArgumentException::class, message: 'Skip reasons cannot be empty.');
    }
}
