<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Supplies one argument list in an attribute for a test method.
 *
 * The optional label becomes the data-set key in the test ID. A row without a
 * label uses its position. Use `#[DataSet]` if an attribute cannot express a
 * value.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class DataRow
{
    /**
     * @param array<mixed> $arguments
     * @param non-empty-string|null $label
     */
    public function __construct(
        public array $arguments,
        public ?string $label = null,
    ) {}
}
