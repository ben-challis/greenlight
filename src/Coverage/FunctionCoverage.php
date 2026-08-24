<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

/**
 * Stores deterministic branch and path coverage for one named function scope.
 *
 * @internal
 */
final readonly class FunctionCoverage
{
    /** @var non-empty-string */
    public string $name;

    /** @var list<BranchCoverage> */
    public array $branches;

    /** @var list<PathCoverage> */
    public array $paths;

    /**
     * @param list<BranchCoverage> $branches
     * @param list<PathCoverage> $paths
     */
    public function __construct(string $name, array $branches, array $paths)
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Use a non-empty coverage function name.');
        }

        $this->name = $name;
        $byId = [];

        foreach ($branches as $branch) {
            $existing = $byId[$branch->id] ?? null;
            $byId[$branch->id] = $existing instanceof BranchCoverage ? $existing->merge($branch) : $branch;
        }

        \ksort($byId);
        $this->branches = \array_values($byId);
        $byPath = [];

        foreach ($paths as $path) {
            $identity = $path->identity();
            $existing = $byPath[$identity] ?? null;
            $byPath[$identity] = $existing instanceof PathCoverage ? $existing->merge($path) : $path;
        }

        \ksort($byPath, \SORT_STRING);
        $this->paths = \array_values($byPath);
    }

    public function merge(self $other): self
    {
        if ($this->name !== $other->name) {
            throw new \LogicException(\sprintf('Cannot merge function coverage of "%s" into "%s".', $other->name, $this->name));
        }

        return new self($this->name, [...$this->branches, ...$other->branches], [...$this->paths, ...$other->paths]);
    }

    public function branchTotal(): int
    {
        return \count($this->branches);
    }

    public function coveredBranchTotal(): int
    {
        return \count(\array_filter($this->branches, static fn(BranchCoverage $branch): bool => $branch->covered));
    }

    public function pathTotal(): int
    {
        return \count($this->paths);
    }

    public function coveredPathTotal(): int
    {
        return \count(\array_filter($this->paths, static fn(PathCoverage $path): bool => $path->covered));
    }

    /** @return array{name: non-empty-string, branches: list<array<string, mixed>>, paths: list<array<string, mixed>>} */
    public function toWire(): array
    {
        return [
            'name' => $this->name,
            'branches' => \array_map(static fn(BranchCoverage $branch): array => $branch->toWire(), $this->branches),
            'paths' => \array_map(static fn(PathCoverage $path): array => $path->toWire(), $this->paths),
        ];
    }

    /** @param array<int, true> $ignored */
    public function withoutLines(array $ignored): ?self
    {
        $branches = [];
        $removed = [];

        foreach ($this->branches as $branch) {
            $remove = false;

            for ($line = $branch->startLine; $line <= $branch->endLine; ++$line) {
                if (isset($ignored[$line])) {
                    $remove = true;
                    break;
                }
            }

            if ($remove) {
                $removed[$branch->id] = true;
            } else {
                $branches[] = $branch;
            }
        }

        $paths = \array_values(\array_filter(
            $this->paths,
            static fn(PathCoverage $path): bool => \array_all(
                $path->branches,
                static fn(int $branch): bool => !isset($removed[$branch]),
            ),
        ));

        return $branches === [] && $paths === [] ? null : new self($this->name, $branches, $paths);
    }
}
