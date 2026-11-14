<?php

declare(strict_types=1);

namespace Greenlight\Cli\Signal;

use Greenlight\Internal\Process\EmergencyCleanups;
use Greenlight\Internal\Process\GracefulShutdown;

/**
 * Without ext-pcntl, PHP uses its default immediate-exit behavior. The first
 * signal requests a normal drain. A second signal ends the process.
 *
 * Before the process ends on the second signal, the handler restores the
 * default handlers and runs the emergency cleanups. A third signal during a
 * slow cleanup thus ends the process at once. Then the handler sends the
 * signal again, so the exit status shows the signal.
 *
 * Workers ignore SIGINT. Thus, the orchestrator does not report active tests
 * as crashes. Workers and the watch child run in their own process groups.
 * The emergency cleanups stop them, because the terminal does not.
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
        ?EmergencyCleanups $cleanups = null,
    ): void {
        $operations ??= new SystemSignalOperations();

        if (!$operations->available()) {
            return;
        }

        $operations->enableAsync();

        $forced = static function (int $signal) use ($operations, $cleanups): void {
            $operations->register(\SIGINT, \SIG_DFL);
            $operations->register(\SIGTERM, \SIG_DFL);
            $cleanups?->run();
            $operations->raise($signal);
        };

        $graceful = static function (int $signal) use ($shutdown, $operations, $forced): void {
            $shutdown->request($signal);
            $operations->register(\SIGINT, $forced);
            $operations->register(\SIGTERM, $forced);
        };

        $operations->register(\SIGINT, $graceful);
        $operations->register(\SIGTERM, $graceful);
    }
}
