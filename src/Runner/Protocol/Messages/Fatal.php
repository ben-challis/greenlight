<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Wire\Wire;
use Greenlight\Runner\Protocol\Message;

/**
 * Worker to orchestrator: last-gasp report of an unhandled framework error
 * before an orderly abnormal exit. Not a test failure.
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
