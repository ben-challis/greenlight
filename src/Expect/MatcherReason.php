<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Result\FailureDetail;

/**
 * Normalizes matcher reasons and reports an empty reason.
 *
 * @internal
 */
final class MatcherReason
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /** @return non-empty-string|null */
    public static function normalize(string $reason): ?string
    {
        $reason = \trim($reason);

        return $reason === '' ? null : $reason;
    }

    /**
     * @return non-empty-string
     *
     * @throws ExpectationFailed
     */
    public static function validate(string $reason, ?string $actual = null): string
    {
        return self::normalize($reason) ?? throw self::emptyFailure($actual);
    }

    public static function emptyFailure(?string $actual): ExpectationFailed
    {
        return ExpectationFailed::fromDetail(new FailureDetail(
            'because() requires a non-empty reason.',
            actual: $actual,
            location: CallSite::capture(),
        ));
    }
}
