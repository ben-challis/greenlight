<?php

declare(strict_types=1);

namespace Greenlight\Rector;

use PhpParser\Node\Expr;

/**
 * Records how one expectException block becomes a toThrow() expectation:
 * where the block starts, which throwable it names, and the optional
 * pattern. The PHPUnit message checks are substring or regex checks, so
 * both convert to the toThrow() pattern constraint and never to the exact
 * message constraint.
 *
 * @internal
 */
final readonly class ThrowRewrite
{
    /**
     * @param int<0, max> $firstExpectationIndex
     */
    public function __construct(
        public int $firstExpectationIndex,
        public ?Expr $exception,
        public ?Expr $matching,
    ) {}
}
