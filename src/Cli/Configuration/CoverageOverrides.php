<?php

declare(strict_types=1);

namespace Greenlight\Cli\Configuration;

use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Internal\Text\DecimalInteger;

/** Contains validated command-line coverage changes.
 *
 * @internal
 */
final readonly class CoverageOverrides
{
    /**
     * @param list<non-empty-string> $includePaths
     * @param non-empty-string|null $perTestTarget
     * @param int<0, max>|null $maximumUncoveredLines
     * @param int<0, max>|null $maximumUncoveredBranches
     */
    public function __construct(
        public array $includePaths = [],
        public ?string $perTestTarget = null,
        public bool $disabled = false,
        public ?float $minimumPercentage = null,
        public ?int $maximumUncoveredLines = null,
        public bool $requireDriver = false,
        public bool $branchCoverage = false,
        public ?float $minimumBranchPercentage = null,
        public ?int $maximumUncoveredBranches = null,
    ) {}

    public function enablesCoverage(): bool
    {
        return $this->includePaths !== []
            || $this->perTestTarget !== null
            || $this->minimumPercentage !== null
            || $this->maximumUncoveredLines !== null
            || $this->requireDriver
            || $this->branchCoverage
            || $this->minimumBranchPercentage !== null
            || $this->maximumUncoveredBranches !== null;
    }

    /** @throws CliError */
    public static function fromArguments(ParsedArguments $arguments): self
    {
        $includePaths = [];

        foreach ($arguments->values('coverage-include') as $path) {
            if ($path === '') {
                throw CliError::optionRequiresValue('coverage-include');
            }

            $includePaths[] = $path;
        }

        $perTestTarget = null;

        if ($arguments->has('coverage-map')) {
            $perTestTarget = $arguments->value('coverage-map');

            if ($perTestTarget === null || $perTestTarget === '') {
                throw CliError::optionRequiresValue('coverage-map');
            }
        }

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

        $minimumBranchPercentage = null;

        if ($arguments->has('minimum-branch-coverage')) {
            $raw = $arguments->value('minimum-branch-coverage') ?? '';

            if (\preg_match('/^(?:100(?:\.0{1,2})?|(?:\d|[1-9]\d)(?:\.\d{1,2})?)\z/', $raw) !== 1) {
                throw CliError::invalidBranchCoveragePercentage($raw);
            }

            $minimumBranchPercentage = (float) $raw;
        }

        $maximumUncoveredBranches = null;

        if ($arguments->has('maximum-uncovered-branches')) {
            $raw = $arguments->value('maximum-uncovered-branches') ?? '';
            $maximumUncoveredBranches = DecimalInteger::parse($raw);

            if ($maximumUncoveredBranches === null) {
                throw CliError::notANonNegativeInteger('--maximum-uncovered-branches', $raw);
            }
        }

        $overrides = new self(
            includePaths: $includePaths,
            perTestTarget: $perTestTarget,
            disabled: $arguments->has('no-coverage'),
            minimumPercentage: $minimumPercentage,
            maximumUncoveredLines: $maximumUncoveredLines,
            requireDriver: $arguments->has('require-coverage-driver'),
            branchCoverage: $arguments->has('branch-coverage'),
            minimumBranchPercentage: $minimumBranchPercentage,
            maximumUncoveredBranches: $maximumUncoveredBranches,
        );

        if ($overrides->disabled && $overrides->enablesCoverage()) {
            throw CliError::coverageOptionsConflict();
        }

        return $overrides;
    }
}
