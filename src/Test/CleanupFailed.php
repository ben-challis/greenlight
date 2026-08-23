<?php

declare(strict_types=1);

namespace Greenlight\Test;

/**
 * One or more cleanup callbacks failed.
 *
 * @internal
 */
final class CleanupFailed extends \RuntimeException
{
    /**
     * @param non-empty-list<\Throwable> $failures
     */
    private function __construct(
        public readonly array $failures,
    ) {
        parent::__construct('Test cleanup failed.', previous: $failures[0]);
    }

    /**
     * @param non-empty-list<\Throwable> $failures
     */
    public static function fromFailures(array $failures): self
    {
        return new self($failures);
    }
}
