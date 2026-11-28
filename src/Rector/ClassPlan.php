<?php

declare(strict_types=1);

namespace Greenlight\Rector;

use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;

/**
 * The validated conversion plan for one PHPUnit test class. A plan exists
 * only when every member converts, so the conversion itself cannot fail
 * part-way through. The keys and entries use lowercase method names because
 * PHP method calls are case-insensitive.
 *
 * @internal
 */
final readonly class ClassPlan
{
    /**
     * @param array<int, list<AttributeGroup>> $attributeGroups replacement attributes keyed by the object ID of the class or method
     * @param array<string, 'after'|'before'> $hooks
     * @param list<string> $tests
     * @param list<string> $noExpectations
     * @param array<string, ThrowRewrite> $throwRewrites
     * @param array<int, Expr> $calls replacement expressions keyed by the object ID of the original call
     */
    public function __construct(
        public array $attributeGroups,
        public array $hooks,
        public array $tests,
        public array $noExpectations,
        public array $throwRewrites,
        public array $calls,
    ) {}
}
