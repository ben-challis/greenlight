<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol\Messages;

use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Wire\Wire;

/**
 * Reports an unhandled framework error from a worker to the orchestrator.
 * The worker sends this message before an orderly abnormal exit. This error
 * is not a test failure.
 *
 * @internal
 */
final readonly class Fatal implements Message
{
    public function __construct(public ThrowableDetail $detail) {}

    #[\Override]
    public static function tag(): string
    {
        return 'fatal';
    }

    #[\Override]
    public function toWire(): array
    {
        return ['detail' => $this->detail->toWire()];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(ThrowableDetail::fromWire(Wire::map($payload, 'detail')));
    }
}
