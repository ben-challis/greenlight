<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

/**
 * Identifies one ordered exit from a branch and records its hit state.
 *
 * @internal
 */
final readonly class BranchExitCoverage
{
    /** @var int<0, max> */
    public int $id;

    public function __construct(
        int $id,
        public bool $covered,
    ) {
        if ($id < 0) {
            throw new \InvalidArgumentException('A branch exit ID MUST NOT be negative.');
        }

        $this->id = $id;
    }

    public function merge(self $other): self
    {
        if ($this->id !== $other->id) {
            throw new \LogicException('Cannot merge branch exits with different identities.');
        }

        return new self($this->id, $this->covered || $other->covered);
    }

    /** @return array{id: int, covered: bool} */
    public function toWire(): array
    {
        return ['id' => $this->id, 'covered' => $this->covered];
    }
}
