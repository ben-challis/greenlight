<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

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
        $this->assertValidName($name);
        $this->name = $name;
    }

    /**
     * @phpstan-assert non-empty-string $name
     *
     * @throws \InvalidArgumentException
     */
    private function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Group names cannot be empty.');
        }
    }
}
