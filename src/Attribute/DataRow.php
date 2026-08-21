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
     * @var non-empty-string|null
     */
    public ?string $label;

    /**
     * @param array<mixed> $arguments
     * @param non-empty-string|null $label
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public array $arguments,
        ?string $label = null,
    ) {
        $this->label = $this->validatedLabel($label);
    }

    /**
     * @return non-empty-string|null
     *
     * @throws \InvalidArgumentException
     */
    private function validatedLabel(?string $label): ?string
    {
        if ($label === '') {
            throw new \InvalidArgumentException('Data row label must not be empty.');
        }

        return $label;
    }
}
