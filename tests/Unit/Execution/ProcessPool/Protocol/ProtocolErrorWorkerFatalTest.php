<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Expect\Expect;

final class ProtocolErrorWorkerFatalTest
{
    #[Test]
    public function workerFatalAttributesTheWorkerAndSource(): void
    {
        $error = ProtocolError::workerFatal(
            'worker-5',
            'The worker could not load its configuration',
            '/project/greenlight.php',
            17,
        );

        Expect::that($error->getMessage())
            ->because(
                'a fatal worker error MUST identify its worker, message, and source location',
            )
            ->toStartWith('Worker "worker-5" reported a fatal Greenlight error:')
            ->toContain('The worker could not load its configuration')
            ->toContain('(/project/greenlight.php:17)');
    }
}
