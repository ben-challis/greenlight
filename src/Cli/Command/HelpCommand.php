<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Input\Definition;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Output\HelpFormatter;
use Greenlight\Plugin\CommandResult;

/**
 * Prints the command-line help for --help and the version for --version.
 * Each bundled command accepts both options.
 *
 * @internal
 */
final readonly class HelpCommand
{
    /** @param non-empty-string $version */
    public function __construct(private Console $console, private string $version) {}

    public static function selects(ParsedArguments $arguments): bool
    {
        return $arguments->has('help') || $arguments->has('version');
    }

    public function run(ParsedArguments $arguments): CommandResult
    {
        if ($arguments->has('help')) {
            $style = $this->console->stdoutStyle($arguments->has('no-ansi'), $arguments->has('ansi'));
            $this->console->out(new HelpFormatter($style)->format(Definition::HELP) . "\n");

            return CommandResult::success();
        }

        $this->console->out('Greenlight ' . $this->version . "\n");

        return CommandResult::success();
    }
}
