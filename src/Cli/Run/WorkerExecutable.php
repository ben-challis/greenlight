<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

use Greenlight\Internal\Php\ErrorTrap;

/**
 * Selects an executable worker binary for the current process.
 *
 * @internal
 */
final class WorkerExecutable
{
    /** @var list<non-empty-string> */
    private const array REQUIRED_FUNCTIONS = [
        'proc_open', 'proc_get_status', 'proc_terminate', 'proc_close',
        'stream_socket_server', 'stream_socket_get_name', 'stream_socket_accept',
        'stream_socket_client', 'stream_select', 'stream_set_blocking',
    ];

    /** @codeCoverageIgnore */
    private function __construct() {}

    /** @return non-empty-string|false */
    public static function resolve(?string $binPath): string|false
    {
        if ($binPath === null || !\array_all(self::REQUIRED_FUNCTIONS, static fn(string $function): bool => \function_exists($function))) {
            return false;
        }

        return ErrorTrap::run(static fn() => \realpath($binPath));
    }
}
