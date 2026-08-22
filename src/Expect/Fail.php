<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Result\FailureDetail;
use Greenlight\Test\ExpectationCounter;

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
            $reason === '' ? 'The test failed without a reason.' : $reason,
            location: CallSite::capture(),
        ));
    }
}
