<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\SystemSignalOperations;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\Subprocess;

final class SystemSignalOperationsTest
{
    #[Test]
    public function availableOperationsUpdateTheNativePcntlState(): void
    {
        $operations = new SystemSignalOperations();
        $available = \function_exists('pcntl_signal') && \function_exists('pcntl_async_signals');

        Expect::that($operations->available())
            ->because('native signal availability MUST match the required pcntl functions')
            ->toBe($available);

        if (!$available) {
            return;
        }

        $asyncBefore = \pcntl_async_signals();
        $handlerBefore = \pcntl_signal_get_handler(\SIGUSR1);
        $handler = static function (): void {};

        try {
            $operations->enableAsync();
            $operations->register(\SIGUSR1, $handler);

            Expect::that(\pcntl_async_signals())
                ->because('native signal operations MUST enable asynchronous delivery')
                ->toBeTrue()
                ->and(\pcntl_signal_get_handler(\SIGUSR1))
                ->because('native signal operations MUST register the exact handler')
                ->toBe($handler);
        } finally {
            \pcntl_signal(\SIGUSR1, $handlerBefore);
            \pcntl_async_signals($asyncBefore);
        }
    }

    #[Test]
    #[DataSet('requiredPcntlFunctions')]
    public function availabilityRequiresEveryPcntlFunction(string $function): void
    {
        $root = \dirname(__DIR__, 3);
        $result = Subprocess::run($root, [
            \PHP_BINARY,
            '-d',
            'disable_functions=' . $function,
            '-r',
            <<<'PHP'
            require $argv[1];

            echo (new \Greenlight\Cli\SystemSignalOperations())->available()
                ? 'available'
                : 'unavailable';
            PHP,
            $root . '/vendor/autoload.php',
        ]);

        Expect::that($result->exitCode)
            ->because(\sprintf('the capability check runs with %s disabled', $function))
            ->toBe(0)
            ->and($result->stdout)
            ->because(\sprintf('native signal handling requires %s', $function))
            ->toBe('unavailable')
            ->and($result->stderr)
            ->toBe('');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function requiredPcntlFunctions(): iterable
    {
        yield 'signal registration' => ['pcntl_signal'];
        yield 'asynchronous delivery' => ['pcntl_async_signals'];
    }
}
