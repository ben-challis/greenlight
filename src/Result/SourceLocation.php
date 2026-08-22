<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

final readonly class SourceLocation implements \Stringable
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

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
        ];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'file'),
            \max(1, Wire::int($payload, 'line')),
        );
    }
}
