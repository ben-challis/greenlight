<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

use Greenlight\Cli\Output\ExitCode;

/**
 * Contains the observable result and saved scheduling data for one run attempt.
 *
 * @internal
 */
final readonly class RunAttemptResult
{
    /**
     * @param list<non-empty-string> $failedTests
     * @param array<non-empty-string, float> $classSeconds
     */
    public function __construct(
        public ExitCode $exitCode,
        public array $failedTests,
        public array $classSeconds,
    ) {}
}
