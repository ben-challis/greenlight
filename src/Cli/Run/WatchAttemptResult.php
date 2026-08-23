<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

/**
 * Contains failure and coverage-map state from one watch attempt.
 *
 * @internal
 */
final readonly class WatchAttemptResult
{
    /**
     * @param list<non-empty-string> $failedTests
     * @param list<non-empty-string> $failedClasses
     */
    public function __construct(
        public array $failedTests,
        public array $failedClasses,
        public bool $completed,
        public bool $mapPublished,
        public ?string $mapRunId = null,
    ) {}
}
