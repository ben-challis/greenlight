<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * Describes one file change and optional content from both sides of the change.
 *
 * @internal
 */
final readonly class FileChange
{
    /** @param non-empty-string $path */
    public function __construct(
        public string $path,
        public bool $existedBefore,
        public bool $existsAfter,
        public ?string $before = null,
        public ?string $after = null,
    ) {}

    /** @param non-empty-string $path */
    public static function unknown(string $path): self
    {
        return new self($path, true, true);
    }

    public function isAdded(): bool
    {
        return !$this->existedBefore && $this->existsAfter;
    }

    public function isDeleted(): bool
    {
        return $this->existedBefore && !$this->existsAfter;
    }

    public function hasLineCountChange(): bool
    {
        return $this->before === null
            || $this->after === null
            || \count($this->lines($this->before)) !== \count($this->lines($this->after));
    }

    /** @return list<positive-int>|null */
    public function changedLines(): ?array
    {
        if ($this->before === null || $this->after === null || $this->hasLineCountChange()) {
            return null;
        }

        $before = $this->lines($this->before);
        $after = $this->lines($this->after);
        $changed = [];

        foreach ($before as $offset => $line) {
            if ($line !== $after[$offset]) {
                $changed[] = $offset + 1;
            }
        }

        return $changed;
    }

    public function followedBy(self $later): self
    {
        if ($later->path !== $this->path) {
            throw new \InvalidArgumentException('Combine file changes only when their paths are equal.');
        }

        return new self(
            $this->path,
            $this->existedBefore,
            $later->existsAfter,
            $this->before,
            $later->after,
        );
    }

    /** @return list<string> */
    private function lines(string $contents): array
    {
        return \explode("\n", $contents);
    }
}
