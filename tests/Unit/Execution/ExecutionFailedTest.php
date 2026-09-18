<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ExecutionFailed;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;

use function Greenlight\expect;

final readonly class ExecutionFailedTest
{
    #[Test]
    public function processPoolFailuresPreserveTheProtocolFailure(): void
    {
        $protocol = ProtocolError::malformedFrame('probe');
        $execution = ExecutionFailed::processPool($protocol);

        expect($execution->getMessage())->toBe($protocol->getMessage());
        expect($execution->getPrevious())->toBe($protocol);
    }
}
