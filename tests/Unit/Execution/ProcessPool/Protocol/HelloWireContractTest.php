<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;
use Greenlight\Expect\Expect;

final readonly class HelloWireContractTest
{
    #[Test]
    public function workerIntroductionKeepsItsExactWireValues(): void
    {
        Expect::that(new Hello('worker-7', 'run-token', 321)->toWire())
            ->because('a worker introduction MUST keep its identity, token, and process ID')
            ->toBe([
                'workerId' => 'worker-7',
                'token' => 'run-token',
                'pid' => 321,
            ]);
    }
}
