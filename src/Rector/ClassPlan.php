<?php

declare(strict_types=1);

namespace Greenlight\Rector;

use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * The validated conversion plan for one PHPUnit test class. A plan exists
 * only when every member converts, so the conversion itself cannot fail
 * part-way through. Each map uses the object ID of the original node as its
 * key. The rule applies the stored replacements and decides nothing again.
 *
 * @internal
 */
final readonly class ClassPlan
{
    /**
     * @param array<int, list<AttributeGroup>> $attributeGroups replacement
     *   attributes for the class and each method
     * @param array<int, true> $publicMethods methods that become public
     * @param array<int, list<Stmt>> $statements replacement method bodies
     * @param array<int, Expr> $calls replacement expressions for calls
     */
    public function __construct(
        public array $attributeGroups,
        public array $publicMethods,
        public array $statements,
        public array $calls,
    ) {}
}
