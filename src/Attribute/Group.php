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
        $this->name = $this->validatedName($name);
    }

    /**
     * @return non-empty-string
     *
     * @throws \InvalidArgumentException
     */
    private function validatedName(string $name): string
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Group names cannot be empty.');
        }

        return $name;
    }
}
