<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * Contains the failed classes and coverage-map state after one watch run.
 *
 * @internal
 */
final readonly class WatchLoopResult
{
    /** @param list<non-empty-string> $failedClasses */
    public function __construct(
        public array $failedClasses,
        public bool $mapPublished = false,
    ) {}
}
