<?php

declare(strict_types=1);

namespace Greenlight\Amp;

use Amp\DeferredCancellation;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Plugin\TestAttemptLifecycle;
use Greenlight\Plugin\TestAttemptRunner;
use Revolt\EventLoop;

/**
 * Connects test deadlines and temporal polling to the application's Revolt scheduler.
 * Requires the optional amphp/amp 3.1 and revolt/event-loop 1.x packages.
 */
final class AmpPlugin implements TestAttemptLifecycle, TestAttemptRunner
{
    private ?AmpAttempt $attempt = null;

    /**
     * @template T
     *
     * @param \Closure(): T $attempt
     *
     * @return T
     */
    #[\Override]
    public function runTestAttempt(\Closure $attempt): mixed
    {
        if (!\class_exists(DeferredCancellation::class) || !\class_exists(EventLoop::class) || !\function_exists('Amp\\delay')) {
            throw AmpBridgeError::runtimeUnavailable();
        }

        if ($this->attempt instanceof AmpAttempt) {
            throw AmpBridgeError::overlappingAttempts();
        }

        $this->attempt = $state = new AmpAttempt();

        try {
            return ExpectationRuntime::withClock(
                new AmpPollingClock(),
                static fn(): mixed => AmpContext::withScope(new AmpScope($state, false), $attempt),
            );
        } finally {
            try {
                $state->close();
            } finally {
                $this->attempt = null;
            }
        }
    }

    #[\Override]
    public function enterTestAttempt(?float $deadline): void
    {
        $this->state()->enter($deadline);
    }

    #[\Override]
    public function leaveTestBody(): void
    {
        $this->state()->leaveBody();
    }

    #[\Override]
    public function leaveTestAttempt(): void
    {
        $this->state()->close();
    }

    private function state(): AmpAttempt
    {
        return $this->attempt ?? throw AmpBridgeError::contextUnavailable();
    }
}
