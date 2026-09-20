<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;

use function Greenlight\expect;

final readonly class HelloWireContractTest
{
    #[Test]
    public function workerIntroductionKeepsItsExactWireValues(): void
    {
        expect(new Hello('worker-7', 'run-token', 321)->toWire())
            ->because('a worker introduction MUST keep its identity, token, and process ID')
            ->toBe([
                'workerId' => 'worker-7',
                'token' => 'run-token',
                'pid' => 321,
            ]);
    }
}
