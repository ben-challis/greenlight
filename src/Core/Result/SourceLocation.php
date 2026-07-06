<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * @internal
 */
final readonly class SourceLocation implements WireSerializable, \Stringable
{
    /**
     * @param non-empty-string $file
     * @param positive-int $line
     */
    public function __construct(
        public string $file,
        public int $line,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        return $this->file . ':' . $this->line;
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'file'),
            \max(1, Wire::int($payload, 'line')),
        );
    }
}
