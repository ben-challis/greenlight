<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

use Greenlight\Core\Test\ResourceName;

/**
 * Declares a named resource consumed by the test's class scheduling unit.
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
