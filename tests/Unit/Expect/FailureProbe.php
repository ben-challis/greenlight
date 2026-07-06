<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Core\Result\FailureDetail;
use Greenlight\Expect\ExpectationFailed;

/**
 * Runs a closure that is expected to fail an expectation and hands back the
 * first FailureDetail, so the specs can assert on failure messages using
 * Expect itself.
 */
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

        throw new \RuntimeException('Expected the expectation to fail, but it passed.');
    }
}
