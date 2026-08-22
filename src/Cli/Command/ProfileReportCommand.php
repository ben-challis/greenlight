<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Event\EventTags;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Reporting\ProfileAggregator;
use Greenlight\Reporting\Style;
use Greenlight\Wire\InvalidWirePayload;
use Greenlight\Wire\Wire;
use Greenlight\Wire\WireCommunicationFailed;

/**
 * Creates a profile report from a saved JSONL event stream.
 *
 * @internal
 */
final readonly class ProfileReportCommand
{
    public function __construct(private Console $console) {}

    /** @throws WireCommunicationFailed */
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
                $decoded = \json_decode($line, true, 32, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->console->err("The input is not a JSONL event stream. A line is not valid JSON.\n");
                return 1;
            }
            if (!\is_array($decoded) || ($decoded !== [] && \array_is_list($decoded))) {
                $this->console->err("The input is not a JSONL event stream. A line does not contain an event envelope.\n");
                return 1;
            }
            $envelope = [];
            foreach ($decoded as $key => $value) {
                if (!\is_string($key)) {
                    $this->console->err("The input is not a JSONL event stream. A line does not contain an event envelope.\n");
                    return 1;
                }
                $envelope[$key] = $value;
            }
            try {
                $version = Wire::int($envelope, 'v');
                $tag = Wire::nonEmptyString($envelope, 'event');
                $data = Wire::map($envelope, 'data');
            } catch (InvalidWirePayload) {
                $this->console->err("The input is not a JSONL event stream. A line does not contain an event envelope.\n");
                return 1;
            }
            if (!\in_array($version, [2, 3], true)) {
                $this->console->err(\sprintf("The input uses unsupported JSONL version %d.\n", $version));
                return 1;
            }
            $class = EventTags::classFor($tag);
            if ($class === null) {
                continue;
            }
            try {
                $aggregator->onEvent($class::fromWire($data));
            } catch (InvalidWirePayload $error) {
                $this->console->error(\sprintf('Greenlight could not decode a "%s" event: %s', $tag, $error->getMessage()), $arguments->has('no-ansi'));
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
