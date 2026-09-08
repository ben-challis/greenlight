<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Selects the earliest time limit for one temporal matcher call.
 * A test limit takes precedence when inherited deadlines are equal.
 *
 * @internal
 */
final readonly class TemporalDeadline
{
    private function __construct(
        public float $time,
        public TemporalDeadlineSource $source,
    ) {}

    public static function forWait(float $requestedDeadline): self
    {
        $testDeadline = ExpectationRuntime::deadline();
        $enclosingDeadline = ExpectationRuntime::enclosingDeadline();

        if (
            $testDeadline !== null
            && $testDeadline < $requestedDeadline
            && ($enclosingDeadline === null || $testDeadline <= $enclosingDeadline)
        ) {
            return new self($testDeadline, TemporalDeadlineSource::Test);
        }

        if ($enclosingDeadline !== null && $enclosingDeadline < $requestedDeadline) {
            return new self($enclosingDeadline, TemporalDeadlineSource::Enclosing);
        }

        return new self($requestedDeadline, TemporalDeadlineSource::Local);
    }
}
