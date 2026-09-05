<?php

declare(strict_types=1);

namespace Greenlight\Amp;

use Amp\Cancellation;
use Amp\Future;
use Greenlight\Expect\ExpectationRuntime;

/**
 * Supplies deadline cancellation and child work for an AmpPlugin test attempt.
 * Native Amp operations must receive cancellation explicitly.
 */
final class AmpContext
{
    private static ?AmpScope $mainScope = null;

    /** @var \WeakMap<object, AmpScope>|null */
    private static ?\WeakMap $fiberScopes = null;

    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * Returns a native token for the current absolute deadline.
     * A child also receives cancellation when the test body ends.
     */
    public static function cancellation(): Cancellation
    {
        $scope = self::scope();

        return $scope->attempt->cancellation(ExpectationRuntime::enclosingDeadline(), $scope->child);
    }

    /** Yields to Revolt until the delay completes or the current deadline expires. */
    public static function delay(float $seconds): void
    {
        $cancellation = self::cancellation();
        $cancellation->throwIfRequested();
        \Amp\delay($seconds, cancellation: $cancellation);
        $cancellation->throwIfRequested();
    }

    /**
     * Waits for a future within the current deadline.
     * Cancellation stops this wait. It does not stop the future's producer.
     *
     * @template T
     *
     * @param Future<T> $future
     *
     * @return T
     */
    public static function await(Future $future): mixed
    {
        $scope = self::scope();
        $cancellation = self::cancellation();
        $cancellation->throwIfRequested();

        try {
            $value = $future->await($cancellation);
        } catch (\Throwable $failure) {
            $scope->attempt->observe($future, $failure);

            throw $failure;
        }

        $scope->attempt->observe($future, null);
        $cancellation->throwIfRequested();

        return $value;
    }

    /**
     * Starts registered child work with the current temporal deadline.
     * Greenlight cancels and joins the child before test cleanup.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return Future<T>
     */
    public static function async(\Closure $operation): Future
    {
        return self::scope()->attempt->async($operation, ExpectationRuntime::enclosingDeadline());
    }

    /**
     * Returns scope-end cancellation for a managed child's temporal polling.
     *
     * @internal
     */
    public static function pollingCancellation(): ?Cancellation
    {
        $fiber = \Fiber::getCurrent();
        $scope = $fiber instanceof \Fiber ? (self::$fiberScopes[$fiber] ?? null) : self::$mainScope;

        return $scope instanceof AmpScope && $scope->child ? $scope->attempt->childCancellation() : null;
    }

    /**
     * Runs an operation in an explicit Amp attempt scope.
     *
     * @internal
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public static function withScope(AmpScope $scope, \Closure $operation): mixed
    {
        $fiber = \Fiber::getCurrent();
        $scopes = self::$fiberScopes ??= new \WeakMap();
        $previous = $fiber instanceof \Fiber ? ($scopes[$fiber] ?? null) : self::$mainScope;

        if ($fiber instanceof \Fiber) {
            $scopes[$fiber] = $scope;
        } else {
            self::$mainScope = $scope;
        }

        try {
            return $operation();
        } finally {
            if (!$fiber instanceof \Fiber) {
                self::$mainScope = $previous;
            } elseif ($previous === null) {
                unset($scopes[$fiber]);
            } else {
                $scopes[$fiber] = $previous;
            }
        }
    }

    private static function scope(): AmpScope
    {
        $fiber = \Fiber::getCurrent();
        $scope = $fiber instanceof \Fiber ? (self::$fiberScopes[$fiber] ?? null) : self::$mainScope;

        if (!$scope instanceof AmpScope) {
            throw AmpBridgeError::contextUnavailable();
        }

        $scope->attempt->assertActive();

        return $scope;
    }
}
