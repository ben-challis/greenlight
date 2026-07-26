<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\Wire;
use Greenlight\Runner\Protocol\Message;

/**
 * Worker to orchestrator: a new attempt started for the in-flight test.
 *
 * This lets crash containment preserve retry progress without exposing
 * attempts as separate public test events.
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
