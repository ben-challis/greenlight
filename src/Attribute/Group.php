<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Tags a test method or class with a group name for filtering.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Group
{
    /**
     * @param non-empty-string $name
     */
    public function __construct(public string $name) {}
}
