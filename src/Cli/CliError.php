<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * A user-facing command-line usage error. The application prints the message
 * and exits with the usage error code.
 *
 * @internal
 */
final class CliError extends \RuntimeException
{
    public static function unknownOption(string $option): self
    {
        return new self(\sprintf('Unknown option "%s". Run greenlight --help for the available options.', $option));
    }
}
