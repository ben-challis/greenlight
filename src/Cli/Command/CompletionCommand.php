<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Input\CompletionScripts;
use Greenlight\Cli\Input\Definition;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Output\ExitCode;

/**
 * Prints a completion script for a supported shell.
 *
 * @internal
 */
final readonly class CompletionCommand
{
    public function __construct(private Console $console, private Definition $definition) {}

    /** @param list<string> $arguments */
    public function run(array $arguments): ExitCode
    {
        $shell = $arguments[0] ?? null;
        if ($shell === null) {
            $this->console->err(\sprintf("completion requires a shell argument: %s.\n", \implode(', ', Definition::COMPLETION_SHELLS)));
            return ExitCode::Usage;
        }
        $script = new CompletionScripts($this->definition->options())->render($shell);
        if ($script === null) {
            $this->console->err(\sprintf("Unknown shell \"%s\". Select one of: %s.\n", $shell, \implode(', ', Definition::COMPLETION_SHELLS)));
            return ExitCode::Usage;
        }
        $this->console->out($script);
        return ExitCode::Success;
    }
}
