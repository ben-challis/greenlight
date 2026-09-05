<?php

declare(strict_types=1);

namespace Greenlight\Amp;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Greenlight\Test\DeadlineExceededError;
use Revolt\EventLoop;

/**
 * Converts an absolute deadline to native Amp cancellation.
 * The token owns its timer and releases it when the token or attempt ends.
 *
 * @internal
 */
final class AmpCancellation implements Cancellation
{
    private readonly DeferredCancellation $source;

    private readonly Cancellation $cancellation;

    private ?string $timer = null;

    /** @var array<string, true> */
    private array $subscriptions = [];

    public function __construct(
        private readonly ?float $deadline,
        private readonly bool $testDeadline,
        ?Cancellation $scopeEnd,
    ) {
        $this->source = new DeferredCancellation();
        $this->cancellation = $scopeEnd instanceof Cancellation
            ? new CompositeCancellation($this->source->getCancellation(), $scopeEnd)
            : $this->source->getCancellation();

        if ($deadline === null) {
            return;
        }

        $remaining = $deadline - \hrtime(true) / 1_000_000_000;

        if ($remaining <= 0.0) {
            $this->expire();

            return;
        }

        $source = $this->source;
        $timer = &$this->timer;
        $this->timer = EventLoop::delay($remaining, static function () use ($source, $testDeadline, &$timer): void {
            $timer = null;
            $source->cancel($testDeadline ? DeadlineExceededError::forTest() : DeadlineExceededError::forTemporal());
        });
        EventLoop::unreference($this->timer);
    }

    public function __destruct()
    {
        $this->cancelTimer();
    }

    /** @param \Closure(CancelledException): mixed $callback */
    #[\Override]
    public function subscribe(\Closure $callback): string
    {
        $this->refresh();
        $id = $this->cancellation->subscribe($callback);
        $this->subscriptions[$id] = true;

        if ($this->timer !== null) {
            EventLoop::reference($this->timer);
        }

        return $id;
    }

    #[\Override]
    public function unsubscribe(string $id): void
    {
        $this->cancellation->unsubscribe($id);
        unset($this->subscriptions[$id]);

        if ($this->subscriptions === [] && $this->timer !== null) {
            EventLoop::unreference($this->timer);
        }
    }

    #[\Override]
    public function isRequested(): bool
    {
        $this->refresh();

        return $this->cancellation->isRequested();
    }

    #[\Override]
    public function throwIfRequested(): void
    {
        $this->refresh();
        $this->cancellation->throwIfRequested();
    }

    public function close(): void
    {
        $this->cancelTimer();
        $this->source->cancel(new AmpScopeCancelledError());
    }

    private function refresh(): void
    {
        if ($this->deadline !== null && \hrtime(true) / 1_000_000_000 >= $this->deadline) {
            $this->expire();
        }
    }

    private function expire(): void
    {
        $this->cancelTimer();
        $this->source->cancel($this->testDeadline ? DeadlineExceededError::forTest() : DeadlineExceededError::forTemporal());
    }

    private function cancelTimer(): void
    {
        if ($this->timer !== null) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
    }
}
