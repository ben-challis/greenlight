<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

/**
 * Identifies one branch by its deterministic function-scoped order.
 *
 * @internal
 */
final readonly class BranchCoverage
{
    /** @var int<0, max> */
    public int $id;

    /** @var positive-int */
    public int $startLine;

    /** @var positive-int */
    public int $endLine;

    /** @var list<BranchExitCoverage> */
    public array $exits;

    /** @param list<BranchExitCoverage> $exits */
    public function __construct(
        int $id,
        int $startLine,
        int $endLine,
        public bool $covered,
        array $exits = [],
    ) {
        if ($id < 0) {
            throw new \InvalidArgumentException('A branch ID MUST NOT be negative.');
        }

        if ($startLine < 1 || $endLine < $startLine) {
            throw new \InvalidArgumentException('Branch source lines MUST form a positive ascending range.');
        }

        $this->id = $id;
        $this->startLine = $startLine;
        $this->endLine = $endLine;

        $byId = [];

        foreach ($exits as $exit) {
            $existing = $byId[$exit->id] ?? null;
            $byId[$exit->id] = $existing instanceof BranchExitCoverage ? $existing->merge($exit) : $exit;
        }

        \ksort($byId);
        $this->exits = \array_values($byId);
    }

    public function merge(self $other): self
    {
        if ($this->id !== $other->id
            || $this->startLine !== $other->startLine
            || $this->endLine !== $other->endLine
        ) {
            throw new \LogicException(\sprintf('Branch metadata differs for branch %d.', $this->id));
        }

        return new self(
            $this->id,
            $this->startLine,
            $this->endLine,
            $this->covered || $other->covered,
            [...$this->exits, ...$other->exits],
        );
    }

    /** @return array{id: int, startLine: int, endLine: int, covered: bool, exits: list<array{id: int, covered: bool}>} */
    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'startLine' => $this->startLine,
            'endLine' => $this->endLine,
            'covered' => $this->covered,
            'exits' => \array_map(static fn(BranchExitCoverage $exit): array => $exit->toWire(), $this->exits),
        ];
    }
}
