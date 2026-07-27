<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\Wire;
use Greenlight\Runner\Protocol\Message;

/**
 * Tells the orchestrator that a worker started a new attempt for the active test.
 *
 * Crash containment uses this message to preserve retry progress. Test
 * attempts do not become separate public test events.
 *
 * @internal
 */
final readonly class AttemptStarted implements Message
{
    /**
     * @param positive-int $attempt
     */
    public function __construct(
        public TestId $id,
        public int $attempt,
    ) {}

    #[\Override]
    public static function tag(): string
    {
        return 'attempt-started';
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'id' => $this->id->toWire(),
            'attempt' => $this->attempt,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            TestId::fromWire(Wire::map($payload, 'id')),
            \max(1, Wire::int($payload, 'attempt')),
        );
    }
}
