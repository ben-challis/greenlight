<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\Messages\Hello;

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
