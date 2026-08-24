<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

/**
 * Identifies one Xdebug path by its exact ordered branch sequence.
 *
 * @internal
 */
final readonly class PathCoverage
{
    /** @var non-empty-list<int<0, max>> */
    public array $branches;

    /** @param non-empty-list<int<0, max>> $branches */
    public function __construct(array $branches, public bool $covered)
    {
        if ($branches === []) {
            throw new \InvalidArgumentException('A path MUST contain at least one branch ID.');
        }

        foreach ($branches as $branch) {
            if ($branch < 0) {
                throw new \InvalidArgumentException('Path branch IDs MUST NOT be negative.');
            }
        }

        $this->branches = $branches;
    }

    public function identity(): string
    {
        return \implode(':', $this->branches);
    }

    public function merge(self $other): self
    {
        if ($this->branches !== $other->branches) {
            throw new \LogicException('Cannot merge paths with different branch sequences.');
        }

        return new self($this->branches, $this->covered || $other->covered);
    }

    /** @return array{branches: non-empty-list<int<0, max>>, covered: bool} */
    public function toWire(): array
    {
        return ['branches' => $this->branches, 'covered' => $this->covered];
    }
}
