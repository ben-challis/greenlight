<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Runner\Protocol\Message;
use Greenlight\Test\TestId;
use Greenlight\Wire\Wire;

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
     * @var positive-int
     */
    public int $attempt;

    /**
     * @throws \InvalidArgumentException when the attempt number is not positive
     */
    public function __construct(
        public TestId $id,
        int $attempt,
    ) {
        if ($attempt < 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Attempt numbers MUST be positive. Actual value: %d.',
                $attempt,
            ));
        }

        $this->attempt = $attempt;
    }

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
