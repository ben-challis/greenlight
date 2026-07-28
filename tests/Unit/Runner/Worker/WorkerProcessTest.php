<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\Subprocess;

final class WorkerProcessTest
{
    #[Test]
    public function connectionFailureNamesTheExactAddress(): void
    {
        $root = \dirname(__DIR__, 4);
        $address = 'unix://' . \sys_get_temp_dir()
            . '/greenlight-missing-worker-' . \bin2hex(\random_bytes(6)) . '.sock';

        $result = Subprocess::run($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP'
            require $argv[1];

            exit(new Greenlight\Runner\Worker\WorkerProcess()->run(
                $argv[2],
                'worker-under-test',
                'token',
            ));
            PHP,
            $root . '/vendor/autoload.php',
            $address,
        ]);

        Expect::that($result->exitCode)
            ->because('a worker connection failure MUST fail startup')
            ->toBe(1)
            ->and($result->stderr)
            ->toContain('The worker did not connect to ' . $address . ':');
    }
}
