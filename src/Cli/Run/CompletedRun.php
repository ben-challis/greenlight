<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Execution\RunResult;

/**
 * Contains a coordinated run and the coverage that its attempt collected.
 *
 * @internal
 */
final readonly class CompletedRun
{
    public function __construct(
        public RunResult $run,
        public ?CoverageMap $coverage,
    ) {}
}
