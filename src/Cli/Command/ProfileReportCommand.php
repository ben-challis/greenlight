<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Command\ExitCode;
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

    public function run(ParsedArguments $arguments, string $workingDirectory): ExitCode
    {
        $inputs = $arguments->values('input');
        $input = $inputs[0] ?? null;
        if (\count($inputs) !== 1 || $input === null || $input === '') {
            $this->console->err("profile:report requires --input=<path to a JSONL stream>.\n");
            return ExitCode::usage();
        }
        $path = ConfigurationLoader::absolutePath($input, $workingDirectory);
        $raw = ErrorTrap::run(static fn() => \file_get_contents($path), $warning);
        if (!\is_string($raw)) {
            $this->console->err(\sprintf("Greenlight could not read \"%s\"%s.\n", $path, $warning === null ? '' : ': ' . $warning));
            return ExitCode::failure();
        }
        $aggregator = new ProfileAggregator();
        foreach (\explode("\n", $raw) as $line) {
            if (\trim($line) === '') {
                continue;
            }

            try {
                $aggregator->onEvent(EventCodec::decodeJsonLine($line));
            } catch (EventCodecFailed $failure) {
                $exitCode = $this->handleCodecFailure($failure, $arguments->has('no-ansi'));

                if (!$exitCode instanceof ExitCode) {
                    continue;
                }

                return $exitCode;
            }
        }
        $report = $aggregator->render(new Style($this->console->capabilities($arguments->has('no-ansi'), $arguments->has('ansi'))->color));
        if ($report === '') {
            $this->console->err("The stream has no finished run to profile.\n");
            return ExitCode::failure();
        }
        $this->console->out(\ltrim($report, "\n"));
        return ExitCode::success();
    }

    private function handleCodecFailure(EventCodecFailed $failure, bool $noAnsi): ?ExitCode
    {
        return match ($failure->kind) {
            EventCodecFailureKind::UnknownEvent => null,
            EventCodecFailureKind::MalformedJson => $this->writeInputError(
                'The input is not a JSONL event stream. A line is not valid JSON.',
            ),
            EventCodecFailureKind::UnsupportedJsonVersion => $this->writeUnsupportedVersion($failure),
            EventCodecFailureKind::InvalidEventPayload => $this->writeInvalidEvent($failure, $noAnsi),
            EventCodecFailureKind::UnmappedEvent,
            EventCodecFailureKind::MalformedTaggedPayload,
            EventCodecFailureKind::MalformedJsonEnvelope,
            EventCodecFailureKind::JsonEncodingFailed => $this->writeInputError(
                'The input is not a JSONL event stream. A line does not contain an event envelope.',
            ),
        };
    }

    private function writeUnsupportedVersion(EventCodecFailed $failure): ExitCode
    {
        return $this->writeInputError(\sprintf(
            'The input uses unsupported JSONL version %d.',
            $failure->jsonVersion,
        ));
    }

    private function writeInvalidEvent(EventCodecFailed $failure, bool $noAnsi): ExitCode
    {
        $this->console->error(\sprintf(
            'Greenlight could not decode a "%s" event: %s',
            $failure->eventIdentifier,
            $failure->getMessage(),
        ), $noAnsi);

        return ExitCode::failure();
    }

    private function writeInputError(string $message): ExitCode
    {
        $this->console->err($message . "\n");

        return ExitCode::failure();
    }
}
