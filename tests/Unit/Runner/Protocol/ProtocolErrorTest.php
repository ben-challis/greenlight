<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\ProtocolError;

final readonly class ProtocolErrorTest
{
    #[Test]
    public function workerStartupFailureIncludesCapturedOutputExactly(): void
    {
        $error = ProtocolError::workerNeverConnected(
            'worker-2',
            0.5,
            "PHP Fatal error: boot failed\n",
        );

        Expect::that($error->getMessage())
            ->because('worker startup failures MUST preserve captured output')
            ->toBe(
                'Worker "worker-2" did not connect within 0.5 seconds. '
                . 'The machine can have insufficient resources to start a worker. '
                . 'Greenlight stopped the run to prevent an unlimited wait.'
                . "\nWorker output:\nPHP Fatal error: boot failed\n",
            );
    }
}
