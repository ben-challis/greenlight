<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Expect\Expect;

final readonly class ProtocolErrorZeroWarningTest
{
    #[Test]
    public function malformedFramePreservesAZeroWarning(): void
    {
        Expect::that(ProtocolError::malformedFrame('read failed', '0')->getMessage())
            ->because('the warning string "0" MUST remain distinct from no warning')
            ->toBe('Malformed frame: read failed: 0.');
    }
}
