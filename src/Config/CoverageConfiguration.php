<?php

declare(strict_types=1);

namespace Greenlight\Config;

/** @internal */
final readonly class CoverageConfiguration
{
    /**
     * @param list<non-empty-string> $includePaths
     * @param non-empty-string|null $driver
     * @param list<CoverageExport> $exports
     * @param int<0, max>|null $maximumUncoveredLines
     * @param non-empty-string|null $perTestTarget
     * @param int<0, max>|null $maximumUncoveredBranches
     */
    public function __construct(
        public array $includePaths,
        public ?string $driver,
        public array $exports,
        public ?float $minimumPercentage = null,
        public ?int $maximumUncoveredLines = null,
        public bool $requireDriver = false,
        public ?string $perTestTarget = null,
        public bool $branchCoverage = false,
        public ?float $minimumBranchPercentage = null,
        public ?int $maximumUncoveredBranches = null,
    ) {}

    public function hasGates(): bool
    {
        return $this->minimumPercentage !== null
            || $this->maximumUncoveredLines !== null
            || $this->minimumBranchPercentage !== null
            || $this->maximumUncoveredBranches !== null;
    }

    public function requiresCoverageResult(): bool
    {
        return $this->requireDriver || $this->hasGates();
    }

    public function requiresBranchCoverage(): bool
    {
        return $this->branchCoverage
            || $this->minimumBranchPercentage !== null
            || $this->maximumUncoveredBranches !== null;
    }
}
