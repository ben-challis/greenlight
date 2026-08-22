<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Expect\Expect;

final class ProtocolErrorEmptyDiagnosticsTest
{
    /**
     * @param \Closure(): ProtocolError $error
     */
    #[Test]
    #[DataSet('errorsWithoutDiagnostics')]
    public function emptyDiagnosticsDoNotAddAWorkerOutputSection(\Closure $error): void
    {
        Expect::that($error()->getMessage())
            ->because('an empty diagnostic stream MUST NOT add a misleading worker output section')
            ->not()
            ->toContain("\nWorker output:\n");
    }

    /**
     * @return iterable<string, array{\Closure(): ProtocolError}>
     */
    public static function errorsWithoutDiagnostics(): iterable
    {
        yield 'worker did not connect' => [
            static fn(): ProtocolError => ProtocolError::workerNeverConnected('worker-2', 0.5, ''),
        ];
        yield 'worker stalled' => [
            static fn(): ProtocolError => ProtocolError::workerStalled('worker-7', 2.5, ''),
        ];
    }
}
