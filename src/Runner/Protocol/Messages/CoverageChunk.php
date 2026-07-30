<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Runner\Protocol\Message;

/**
 * Worker to orchestrator: one bounded chunk of a test's covered source lines.
 *
 * @internal
 */
final readonly class CoverageChunk implements Message
{
    /**
     * @param non-empty-string $file
     * @param non-empty-list<positive-int> $lines
     */
    public function __construct(
        public TestId $test,
        public string $file,
        public array $lines,
    ) {}

    #[\Override]
    public static function tag(): string
    {
        return 'coverage';
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'test' => $this->test->toWire(),
            'file' => $this->file,
            'lines' => $this->lines,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $raw = $payload['lines'] ?? null;

        if (!\is_array($raw) || !\array_is_list($raw) || $raw === []) {
            throw InvalidWirePayload::wrongType('lines', 'a non-empty list of positive line numbers', $raw);
        }

        $lines = [];

        foreach ($raw as $line) {
            if (!\is_int($line) || $line < 1) {
                throw InvalidWirePayload::wrongType('lines', 'a non-empty list of positive line numbers', $line);
            }

            $lines[] = $line;
        }

        return new self(
            TestId::fromWire(Wire::map($payload, 'test')),
            Wire::nonEmptyString($payload, 'file'),
            $lines,
        );
    }
}
