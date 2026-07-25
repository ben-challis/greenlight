<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Core\GracefulShutdown;

/**
 * Without ext-pcntl, PHP keeps its default hard-exit behaviour. The first
 * signal requests a normal drain; a second terminates immediately.
 *
 * Workers ignore SIGINT so in-flight tests are not misreported as crashes.
 *
 * @internal
 */
final class SignalHandlers
{
    /** @codeCoverageIgnore */
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
