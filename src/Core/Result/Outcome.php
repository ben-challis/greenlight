<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

/**
 * Terminal outcome of a test. A retried test still ends in exactly one of
 * these; the attempt count lives on TestResult.
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
