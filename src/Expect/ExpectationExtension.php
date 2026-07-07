<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Registration seam for expectation plugins.
 *
 * A plugin implements this interface to contribute matchers; unmatched calls
 * on the expectation chain dispatch to them through Expectation::__call.
 */
interface ExpectationExtension
{
    /**
     * Matcher name (as it would appear on the expectation chain) mapped to its
     * predicate. The predicate receives the subject followed by the matcher
     * arguments, declared with real native parameter types, and must return
     * true for the expectation to hold; anything else fails it. Concrete
     * matchers narrow their parameters, so no closure signature covers them
     * all.
     *
     * @return array<non-empty-string, \Closure>
     */
    public function matchers(): array;
}
