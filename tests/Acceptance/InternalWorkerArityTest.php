<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\GreenlightCli;

final class InternalWorkerArityTest
{
    #[Test]
    public function surplusWorkerArgumentsAreAUsageError(): void
    {
        $result = GreenlightCli::run(
            \dirname(__DIR__, 2),
            ['__worker', 'tcp://127.0.0.1:1', 'worker-1', 'secret-token', 'surplus'],
        );

        Expect::that($result->exitCode)
            ->because('the internal worker entry MUST accept exactly three operands')
            ->toBe(64)
            ->and($result->stderr)
            ->toBe('__worker requires <address> <workerId> <token>.')
            ->and($result->stdout)
            ->toBe('');
    }
}
