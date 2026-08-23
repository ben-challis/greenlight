<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ExecutionFailed;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Expect\Expect;

final readonly class ExecutionFailedTest
{
    #[Test]
    public function processPoolFailuresPreserveTheProtocolFailure(): void
    {
        $protocol = ProtocolError::malformedFrame('probe');
        $execution = ExecutionFailed::processPool($protocol);

        Expect::that($execution->getMessage())->toBe($protocol->getMessage());
        Expect::that($execution->getPrevious())->toBe($protocol);
    }
}
