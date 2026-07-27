<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

/**
 * A retried test has exactly one of these final outcomes. TestResult contains
 * the attempt count.
 */
enum Outcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Errored = 'errored';
    case Skipped = 'skipped';

    public function isSuccessful(): bool
    {
        return match ($this) {
            self::Passed, self::Skipped => true,
            self::Failed, self::Errored => false,
        };
    }
}
