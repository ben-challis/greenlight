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
     * @var non-empty-string
     */
    public string $workerId;

    /**
     * @var non-empty-string
     */
    public string $token;

    /**
     * @var positive-int
     */
    public int $pid;

    /**
     * @throws \InvalidArgumentException when a value does not identify a worker
     */
    public function __construct(
        string $workerId,
        string $token,
        int $pid,
    ) {
        if ($workerId === '') {
            throw new \InvalidArgumentException('Hello messages require a nonempty worker ID.');
        }

        if ($token === '') {
            throw new \InvalidArgumentException('Hello messages require a nonempty authentication token.');
        }

        if ($pid < 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Hello messages require a positive process ID. Actual value: %d.',
                $pid,
            ));
        }

        $this->workerId = $workerId;
        $this->token = $token;
        $this->pid = $pid;
    }

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
