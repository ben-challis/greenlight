<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Execution\ProcessPool\Orchestrator\NativeWorkerTransport;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransportEventKind;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PhpSubprocess;

final readonly class NativeWorkerDiagnosticsTest
{
    #[Test]
    #[DataSet('streams')]
    #[Timeout(30.0)]
    public function largeDiagnosticWritesCompleteAndKeepTheBoundedTail(string $stream): void
    {
        $transport = NativeWorkerTransport::listen(PhpSubprocess::command([
            '-r',
            \sprintf('fwrite(%s, str_repeat("x", 1024 * 1024) . "final diagnostic");', $stream),
        ]), \dirname(__DIR__, 5));

        try {
            $transport->start('diagnostics', 1);
            $disconnected = false;

            while (!$disconnected) {
                foreach ($transport->poll() as $event) {
                    if ($event->kind === WorkerTransportEventKind::WorkerDisconnected) {
                        $disconnected = true;
                    }
                }
            }

            Expect::that($transport->diagnostics('diagnostics'))
                ->toBe(\str_repeat('x', 65_536 - \strlen('final diagnostic')) . 'final diagnostic');
        } finally {
            $transport->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function streams(): iterable
    {
        yield 'standard output' => ['STDOUT'];
        yield 'standard error' => ['STDERR'];
    }
}
