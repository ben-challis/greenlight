<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

/**
 * One indivisible dispatch decision for an idle worker.
 *
 * @internal
 */
final readonly class DispatchDecision
{
    private function __construct(
        public DispatchKind $kind,
        public ?ResourceLease $lease = null,
    ) {}

    public static function assign(ResourceLease $lease): self
    {
        return new self(DispatchKind::Assign, $lease);
    }

    public static function wait(): self
    {
        return new self(DispatchKind::Wait);
    }

    public static function drain(): self
    {
        return new self(DispatchKind::Drain);
    }
}
