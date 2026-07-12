<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Core\GracefulShutdown;

/**
 * Installs SIGINT and SIGTERM handlers that turn the first signal into a
 * graceful shutdown request.
 *
 * install() is a no-op without ext-pcntl; PHP's default hard-exit behaviour
 * is the portable baseline. With pcntl, async signals are enabled
 * and the handler only records the signal on the shared GracefulShutdown
 * flag; the run loops poll that flag and drain through their normal control
 * flow, so worker teardown, socket cleanup, run-state recording, and the
 * reporter summary all still happen.
 *
 * After the first signal fires, the handler restores the default disposition
 * for both signals, so a second Ctrl+C or kill terminates the process
 * immediately while a drain is still in progress.
 *
 * Workers deliberately ignore SIGINT (see WorkerProcess::run) rather than
 * dying with the foreground process group. If they died, crash containment
 * in the orchestrator would attribute every in-flight test to a crash and
 * spend spawn budget on replacements before the drain flag is observed.
 * Ignoring the signal keeps an interrupted run on the ordinary drain path:
 * the orchestrator stops assigning, each worker finishes its current test
 * and reports Done, and the summary cross-check stays intact.
 *
 * @internal
 */
final class SignalHandlers
{
    private function __construct() {}

    public static function install(GracefulShutdown $shutdown): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            return;
        }

        \pcntl_async_signals(true);

        $handler = static function (int $signal) use ($shutdown): void {
            $shutdown->request($signal);
            \pcntl_signal(\SIGINT, \SIG_DFL);
            \pcntl_signal(\SIGTERM, \SIG_DFL);
        };

        \pcntl_signal(\SIGINT, $handler);
        \pcntl_signal(\SIGTERM, $handler);
    }
}
