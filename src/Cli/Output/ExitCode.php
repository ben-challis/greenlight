<?php

declare(strict_types=1);

namespace Greenlight\Cli\Output;

/**
 * Defines the process exit codes that Greenlight commands return.
 *
 * @internal
 */
final class ExitCode
{
    public const int SUCCESS = 0;
    public const int FAILURE = 1;
    public const int USAGE = 64;

    private function __construct() {}
}
