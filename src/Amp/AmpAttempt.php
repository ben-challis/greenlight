<?php

declare(strict_types=1);

namespace Greenlight\Amp;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Test\CleanupFailed;

/**
 * Owns one attempt's deadline tokens and explicitly registered child work.
 * It joins all children before the executor releases test resources.
 *
 * @internal
 */
final class AmpAttempt
{
    private bool $active = false;

    private bool $bodyClosed = false;

    private ?float $deadline = null;

    private readonly DeferredCancellation $childrenCancellation;

    private readonly AmpScopeCancelledError $scopeEnded;

    /** @var list<Future<mixed>> */
    private array $children = [];

    /** @var array<int, \Throwable> */
    private array $childFailures = [];

    /** @var array<int, true> */
    private array $observedChildren = [];

    /** @var \WeakMap<AmpCancellation, null> */
    private readonly \WeakMap $tokens;

    public function __construct()
    {
        $this->childrenCancellation = new DeferredCancellation();
        $this->scopeEnded = new AmpScopeCancelledError();
        $this->tokens = new \WeakMap();
    }

    public function enter(?float $deadline): void
    {
        if ($this->active || $this->bodyClosed) {
            throw AmpBridgeError::overlappingAttempts();
        }

        $this->deadline = $deadline;
        $this->active = true;
    }

    public function assertActive(): void
    {
        if (!$this->active) {
            throw AmpBridgeError::contextUnavailable();
        }
    }

    public function childCancellation(): Cancellation
    {
        return $this->childrenCancellation->getCancellation();
    }

    public function cancellation(?float $temporalDeadline, bool $child): Cancellation
    {
        $this->assertActive();
        $testDeadline = $this->deadline !== null
            && ($temporalDeadline === null || $this->deadline <= $temporalDeadline);
        $token = new AmpCancellation(
            $testDeadline ? $this->deadline : $temporalDeadline,
            $testDeadline,
            $child ? $this->childrenCancellation->getCancellation() : null,
        );
        $this->tokens[$token] = null;

        return $token;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return Future<T>
     */
    public function async(\Closure $operation, ?float $temporalDeadline): Future
    {
        $this->assertActive();

        if ($this->bodyClosed) {
            throw AmpBridgeError::bodyClosed();
        }

        $index = \count($this->children);
        $future = \Amp\async(fn() => $this->runChild($operation, $temporalDeadline, $index));
        $this->children[] = $future->ignore();

        return $future;
    }

    /** @param Future<mixed> $future */
    public function observe(Future $future, ?\Throwable $failure): void
    {
        $index = \array_search($future, $this->children, true);

        if ($index !== false && $future->isComplete()
            && (!$failure instanceof \Throwable || ($this->childFailures[$index] ?? null) === $failure)
        ) {
            $this->observedChildren[$index] = true;
        }
    }

    public function leaveBody(): void
    {
        if ($this->bodyClosed) {
            return;
        }

        $this->bodyClosed = true;
        $this->childrenCancellation->cancel($this->scopeEnded);
        $failures = [];

        foreach ($this->children as $index => $child) {
            try {
                $child->await();
            } catch (\Throwable $threw) {
                if (!isset($this->observedChildren[$index]) && !$this->isScopeEnd($threw)) {
                    $failures[] = $threw;
                }
            }
        }

        $this->children = [];
        $this->childFailures = [];
        $this->observedChildren = [];

        if (\count($failures) === 1) {
            throw $failures[0];
        }

        if ($failures !== []) {
            throw CleanupFailed::fromFailures($failures);
        }
    }

    public function close(): void
    {
        try {
            $this->leaveBody();
        } finally {
            foreach ($this->tokens as $token => $_) {
                $token->close();
            }

            $this->active = false;
        }
    }

    private function isScopeEnd(\Throwable $threw): bool
    {
        return $threw instanceof CancelledException && $threw->getPrevious() === $this->scopeEnded;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function runChild(\Closure $operation, ?float $temporalDeadline, int $index): mixed
    {
        try {
            return AmpContext::withScope(
                new AmpScope($this, true),
                function () use ($operation, $temporalDeadline) {
                    $this->cancellation($temporalDeadline, true)->throwIfRequested();

                    return $temporalDeadline === null
                        ? $operation()
                        : ExpectationRuntime::withDeadline($temporalDeadline, $operation);
                },
            );
        } catch (\Throwable $threw) {
            $this->childFailures[$index] = $threw;

            throw $threw;
        }
    }
}
