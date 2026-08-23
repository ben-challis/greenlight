<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Internal\Event\EventCodecFailed;
use Greenlight\Internal\Event\EventCodecFailureKind;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Reporting\Profile\ProfileAggregator;
use Greenlight\Reporting\Style;

/**
 * Creates a profile report from a saved JSONL event stream.
 *
 * @internal
 */
final readonly class ProfileReportCommand
{
    public function __construct(private Console $console) {}

    public function run(ParsedArguments $arguments, string $workingDirectory): int
    {
        $input = $arguments->value('input');
        if ($input === null || $input === '') {
            $this->console->err("profile:report requires --input=<path to a JSONL stream>.\n");
            return 64;
        }
        $path = ConfigurationLoader::absolutePath($input, $workingDirectory);
        $raw = ErrorTrap::run(static fn() => \file_get_contents($path), $warning);
        if (!\is_string($raw)) {
            $this->console->err(\sprintf("Greenlight could not read \"%s\"%s.\n", $path, $warning === null ? '' : ': ' . $warning));
            return 1;
        }
        $aggregator = new ProfileAggregator();
        foreach (\explode("\n", $raw) as $line) {
            if (\trim($line) === '') {
                continue;
            }

            try {
                $aggregator->onEvent(EventCodec::decodeJsonLine($line));
            } catch (EventCodecFailed $failure) {
                if ($failure->kind === EventCodecFailureKind::UnknownEvent) {
                    continue;
                }

                if ($failure->kind === EventCodecFailureKind::MalformedJson) {
                    $this->console->err("The input is not a JSONL event stream. A line is not valid JSON.\n");
                    return 1;
                }

                if ($failure->kind === EventCodecFailureKind::UnsupportedJsonVersion) {
                    $version = $failure->jsonVersion;

                    if ($version === null) {
                        $this->console->err("The input is not a JSONL event stream. A line does not contain an event envelope.\n");
                        return 1;
                    }

                    $this->console->err(\sprintf("The input uses unsupported JSONL version %d.\n", $version));
                    return 1;
                }

                if ($failure->kind === EventCodecFailureKind::InvalidEventPayload) {
                    $this->console->error(\sprintf(
                        'Greenlight could not decode a "%s" event: %s',
                        $failure->eventIdentifier,
                        $failure->getMessage(),
                    ), $arguments->has('no-ansi'));
                    return 1;
                }

                $this->console->err("The input is not a JSONL event stream. A line does not contain an event envelope.\n");
                return 1;
            }
        }
        $report = $aggregator->render(new Style($this->console->capabilities($arguments->has('no-ansi'), $arguments->has('ansi'))->color));
        if ($report === '') {
            $this->console->err("The stream has no finished run to profile.\n");
            return 1;
        }
        $this->console->out(\ltrim($report, "\n"));
        return 0;
    }
}
