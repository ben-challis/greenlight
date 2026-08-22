<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Internal\Process\GracefulShutdown;

/**
 * Without ext-pcntl, PHP uses its default immediate-exit behavior. The first
 * signal requests a normal drain. A second signal terminates the process
 * immediately.
 *
 * Workers ignore SIGINT. Thus, the orchestrator does not report active tests
 * as crashes.
 *
 * @internal
 */
final class SignalHandlers
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function install(
        GracefulShutdown $shutdown,
        ?SignalOperations $operations = null,
    ): void {
        $operations ??= new SystemSignalOperations();

        if (!$operations->available()) {
            return;
        }

        $operations->enableAsync();

        $handler = static function (int $signal) use ($shutdown, $operations): void {
            $shutdown->request($signal);
            $operations->register(\SIGINT, \SIG_DFL);
            $operations->register(\SIGTERM, \SIG_DFL);
        };

        $operations->register(\SIGINT, $handler);
        $operations->register(\SIGTERM, $handler);
    }
}
