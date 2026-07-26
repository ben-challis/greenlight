<?php

declare(strict_types=1);

namespace Greenlight\Rector;

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
     * @param array<string, 'after'|'before'> $hooks
     * @param list<string> $tests
     * @param list<string> $noExpectations
     * @param array<string, ThrowRewrite> $throwRewrites
     */
    public function __construct(
        public array $hooks,
        public array $tests,
        public array $noExpectations,
        public array $throwRewrites,
    ) {}
}
