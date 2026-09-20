<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Adds a group name to a test method or all tests in a class.
 * Class and method groups combine. Repeat the attribute to add more groups.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Group
{
    /**
     * @var non-empty-string
     */
    public string $name;

    /**
     * @param non-empty-string $name
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $name)
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Group names cannot be empty.');
        }

        $this->name = $name;
    }
}
