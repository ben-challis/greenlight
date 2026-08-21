<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

use Greenlight\Core\Test\ResourceName;

/**
 * Declares a resource that the assignment for the test requires.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class RequiresResource
{
    /**
     * @var non-empty-string
     */
    public string $name;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(string $name)
    {
        ResourceName::assertValid($name);
        $this->name = $name;
    }
}
