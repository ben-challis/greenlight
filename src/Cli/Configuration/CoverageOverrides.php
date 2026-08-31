<?php

declare(strict_types=1);

namespace Greenlight\Cli\Configuration;

use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Internal\Text\DecimalInteger;

/** Contains validated command-line coverage overrides.
 *
 * @internal
 */
final readonly class CoverageOverrides
{
    /** @param int<0, max>|null $maximumUncoveredLines */
    public function __construct(
        public ?float $minimumPercentage = null,
        public ?int $maximumUncoveredLines = null,
        public bool $requireDriver = false,
    ) {}

    public function enablesCoverage(): bool
    {
        return $this->minimumPercentage !== null
            || $this->maximumUncoveredLines !== null
            || $this->requireDriver;
    }

    /** @throws CliError */
    public static function fromArguments(ParsedArguments $arguments): self
    {
        $minimumPercentage = null;

        if ($arguments->has('minimum-coverage')) {
            $raw = $arguments->value('minimum-coverage') ?? '';

            if (\preg_match('/^(?:100(?:\.0{1,2})?|(?:\d|[1-9]\d)(?:\.\d{1,2})?)\z/', $raw) !== 1) {
                throw CliError::invalidCoveragePercentage($raw);
            }

            $minimumPercentage = (float) $raw;
        }

        $maximumUncoveredLines = null;

        if ($arguments->has('maximum-uncovered-lines')) {
            $raw = $arguments->value('maximum-uncovered-lines') ?? '';
            $maximumUncoveredLines = DecimalInteger::parse($raw);

            if ($maximumUncoveredLines === null) {
                throw CliError::notANonNegativeInteger('--maximum-uncovered-lines', $raw);
            }
        }

        return new self(
            $minimumPercentage,
            $maximumUncoveredLines,
            $arguments->has('require-coverage-driver'),
        );
    }
}
