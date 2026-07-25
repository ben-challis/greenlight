<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Core\Result\FailureDetail;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\Fail;

final class FailureProbe
{
    private function __construct() {}

    /**
     * @param callable(): mixed $expectation
     */
    public static function detailOf(callable $expectation): FailureDetail
    {
        try {
            $expectation();
        } catch (ExpectationFailed $failure) {
            return $failure->detail();
        }

        Fail::because('Expected the expectation to fail, but it passed.');
    }
}
