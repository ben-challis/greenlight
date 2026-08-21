<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Plugin\Plugin;

/** Supplies extension matchers through `Expectation::__call()`. */
interface ExpectationExtension extends Plugin
{
    /**
     * Maps each expectation-chain matcher name to its predicate.
     *
     * The predicate receives the subject and then the matcher arguments.
     * Native parameter types declare the arguments. The predicate must return
     * true for the expectation to hold. All other results fail it. Each
     * matcher can use narrower parameters. Thus, one closure signature cannot
     * describe all matchers.
     *
     * @return array<non-empty-string, \Closure>
     */
    // @phpstan-ignore-next-line missingType.callable (Each matcher has its own parameter signature.)
    public function matchers(): array;
}
