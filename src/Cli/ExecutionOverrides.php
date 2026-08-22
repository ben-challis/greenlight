<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\WorkerCount;
use Greenlight\Result\ResultPolicy;

/**
 * Contains command-line values that override execution settings.
 *
 * @internal
 */
final readonly class ExecutionOverrides
{
    /**
     * @param positive-int|null $stopAfterFailures
     * @param array<non-empty-string, positive-int> $resourceLimits
     */
    public function __construct(
        public ?WorkerCount $workers = null,
        public ?int $stopAfterFailures = null,
        public ResultPolicy $policy = new ResultPolicy(),
        public ?string $artifactsDirectory = null,
        public array $resourceLimits = [],
    ) {}
}
