<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Wire\Wire;
use Greenlight\Runner\Protocol\Message;

/**
 * The first message from a worker after connection. It authenticates the
 * worker with the token for the run.
 *
 * @internal
 */
final readonly class Hello implements Message
{
    /**
     * @param non-empty-string $workerId
     * @param non-empty-string $token
     * @param positive-int $pid
     */
    public function __construct(
        public string $workerId,
        public string $token,
        public int $pid,
    ) {}

    #[\Override]
    public static function tag(): string
    {
        return 'hello';
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'workerId' => $this->workerId,
            'token' => $this->token,
            'pid' => $this->pid,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'workerId'),
            Wire::nonEmptyString($payload, 'token'),
            \max(1, Wire::int($payload, 'pid')),
        );
    }
}
