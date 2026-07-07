<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Experimental registration seam for expectation plugins.
 *
 * A plugin implements this interface to contribute matchers and passes
 * instances to the Expect constructor. Registration is the entire contract
 * today: Expect stores the extensions but nothing dispatches to the returned
 * matchers yet.
 */
interface ExpectationExtension
{
    /**
     * Matcher name (as it would appear on the expectation chain) mapped to its
     * predicate. The predicate receives the subject followed by the matcher
     * arguments and returns whether the expectation holds.
     *
     * @return array<non-empty-string, \Closure(mixed, mixed...): bool>
     */
    public function matchers(): array;
}
