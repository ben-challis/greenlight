<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\SystemSignalOperations;
use Greenlight\Expect\Expect;

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
}
