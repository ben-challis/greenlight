<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\FunctionAvailable;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Worker\WorkerProcess;
use Greenlight\Tests\Support\Subprocess;

final readonly class WorkerProcessTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function connectionFailureNamesTheExactAddress(): void
    {
        $root = \dirname(__DIR__, 4);
        $address = 'unix://' . $this->tempDirectory->path() . '/missing-worker.sock';

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
            ->toBe(1);
        Expect::that($result->stderr)
            ->toContain('The worker did not connect to ' . $address . ':');
    }

    #[Test]
    #[SkipUnless(FunctionAvailable::class, 'pcntl_signal_get_handler')]
    public function runRestoresTheCallingProcessInterruptHandler(): void
    {
        $before = \pcntl_signal_get_handler(\SIGINT);
        $callerHandler = static function (): void {};

        try {
            \pcntl_signal(\SIGINT, $callerHandler);

            $address = 'unix://' . $this->tempDirectory->path() . '/missing-worker.sock';

            Expect::that(new WorkerProcess()->run($address, 'worker-under-test', 'token'))
                ->because('a connection failure MUST return control to the calling process')
                ->toBe(1);
            Expect::that(\pcntl_signal_get_handler(\SIGINT))
                ->because('an in-process worker run MUST restore the caller SIGINT handler')
                ->toBe($callerHandler);
        } finally {
            \pcntl_signal(\SIGINT, $before);
        }
    }
}
