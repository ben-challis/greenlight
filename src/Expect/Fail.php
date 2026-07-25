<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Test\ExpectationCounter;

/**
 * Fails a test immediately with a user-provided reason.
 */
final class Fail
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @throws ExpectationFailed
     */
    public static function because(string $reason): never
    {
        ExpectationCounter::increment();

        throw ExpectationFailed::fromDetail(new FailureDetail(
            $reason === '' ? 'Failed without a reason.' : $reason,
            location: CallSite::capture(),
        ));
    }
}
