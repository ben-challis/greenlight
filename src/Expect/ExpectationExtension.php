<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/** Contributes matchers dispatched through Expectation::__call. */
interface ExpectationExtension
{
    /**
     * Matcher name (as it would appear on the expectation chain) mapped to its
     * predicate.
     *
     * The predicate receives the subject followed by the matcher arguments,
     * declared with real native parameter types, and must return true for the
     * expectation to hold; anything else fails it. Concrete matchers narrow
     * their parameters, so no closure signature covers them all.
     *
     * @return array<non-empty-string, \Closure>
     */
    public function matchers(): array;
}
