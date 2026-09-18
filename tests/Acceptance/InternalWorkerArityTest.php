<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Tests\Support\GreenlightCli;

use function Greenlight\expect;

final class InternalWorkerArityTest
{
    #[Test]
    public function surplusWorkerArgumentsAreAUsageError(): void
    {
        $result = GreenlightCli::run(
            \dirname(__DIR__, 2),
            ['__worker', 'tcp://127.0.0.1:1', 'worker-1', 'secret-token', 'surplus'],
        );

        expect($result->exitCode)
            ->because('the internal worker entry MUST accept exactly three operands')
            ->toBe(64);
        expect($result->stderr)
            ->toBe('__worker requires <address> <workerId> <token>.');
        expect($result->stdout)
            ->toBe('');
    }
}
