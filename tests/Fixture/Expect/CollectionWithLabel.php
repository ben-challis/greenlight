<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

/**
 * @extends \ArrayObject<int, int>
 */
final class CollectionWithLabel extends \ArrayObject
{
    /** @param array<int, int> $values */
    public function __construct(array $values, public readonly string $label)
    {
        parent::__construct($values);
    }
}
