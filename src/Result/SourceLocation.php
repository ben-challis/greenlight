<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Wire\Wire;
use Greenlight\Wire\WireSerializable;

final readonly class SourceLocation implements WireSerializable, \Stringable
{
    /**
     * @var non-empty-string
     */
    public string $file;

    /**
     * @var positive-int
     */
    public int $line;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(string $file, int $line)
    {
        if ($file === '') {
            throw new \InvalidArgumentException('Source location file must not be empty.');
        }

        if ($line < 1) {
            throw new \InvalidArgumentException('Source location line must be at least 1.');
        }

        $this->file = $file;
        $this->line = $line;
    }

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
