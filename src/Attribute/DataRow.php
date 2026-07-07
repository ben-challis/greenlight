<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * One inline data set for the test method: the array holds the arguments,
 * in parameter order.
 *
 * Repeatable, and combinable with a #[DataSet] provider on the same method.
 * The label (or "#<position>" when unlabelled) becomes the data-set key shown
 * in test ids.
 *
 * For computed rows, ranges, or objects that attributes cannot express, use
 * a #[DataSet] provider instead.
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
