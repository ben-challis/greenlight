<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

use Greenlight\Core\Condition;

/**
 * Skips the test method, or every test in the class, unless the condition is
 * satisfied.
 *
 * The condition is evaluated at execution time in the worker.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class SkipUnless
{
    /**
     * @param class-string<Condition> $condition
     */
    public function __construct(
        public string $condition,
    ) {}
}
