<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\ProtocolError;

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
