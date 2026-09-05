# Amp applications

`AmpPlugin` connects Greenlight to Amp 3 and Revolt 1. Greenlight keeps these
packages optional. Install them in the application:

```sh
composer require --dev amphp/amp:^3.1 revolt/event-loop:^1.0
```

Register the plugin in `greenlight.php`:

<!-- php-example {"example":"amp-configuration","file":"snippet.php","mode":"file","tools":["rector","phpstan"]} -->
```php
use Greenlight\Amp\AmpPlugin;
use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(static fn(): AmpPlugin => new AmpPlugin());
```

The plugin uses the application's Revolt event loop. Temporal polling yields
through Amp so other tasks can progress between observations. The plugin does
not replace the event-loop driver.

## Supported operations

Use `AmpContext::delay()` for a delay that respects the current deadline.
Use `AmpContext::await()` to wait for an Amp future with that deadline.
Pass `AmpContext::cancellation()` to other Amp APIs that accept a cancellation
token. Get the token where the operation starts.

<!-- php-example {"example":"amp-child-work","file":"snippet.php","mode":"file","tools":["rector","phpstan"]} -->
```php
use Greenlight\Amp\AmpContext;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Expect\Expect;

final class AsyncTest
{
    #[Test]
    #[Timeout(1.0)]
    public function receivesTheChildResult(): void
    {
        $future = AmpContext::async(static function (): string {
            AmpContext::delay(0.010);

            return 'ready';
        });

        Expect::that(AmpContext::await($future))->toBe('ready');
    }
}
```

The effective deadline is the earliest test or active temporal deadline.
It is an absolute monotonic time. A new token does not restart the budget.
The executor supplies the test deadline before constructor injection, inside
the attempt runtime boundary.

A temporal probe can use the same operations. A shorter `within()` limit
cancels a supported wait inside that probe. The first `consistently()`
observation uses the test and enclosing limits. Its own period starts after
the first successful observation.

Greenlight reports deadline cancellation as a failed attempt. Diagnostics
identify the test limit or temporal expectation limit. `retryOnException()`
and `toThrow()` do not consume Greenlight deadline or child-scope cancellation. Other Amp
cancellation retains its normal exception behavior.

## Child work and cleanup

Use `AmpContext::async()` to register child work. Each child receives the
parent's current absolute deadlines. The child can add a shorter temporal
limit. A sibling's temporal limit does not affect it.

Before `After` hooks, Greenlight requests cancellation of registered children
and waits for every child to finish. It then runs deferred cleanup and
per-test service disposal. A retry starts only after this sequence completes.
Child work cannot register more children after this shutdown starts.

Use `AmpContext::await()` to handle a registered child's result or error.
Greenlight reports child errors that this method did not deliver at the join.
Native `Future::await()` does not record this delivery. If you use native waits,
handle expected application errors inside the child operation.

Cleanup retains the test deadline. If the deadline has expired, supported
cleanup waits cancel immediately. Greenlight still calls the remaining hooks,
cleanup callbacks, and service disposal methods. Earlier failures and cleanup
diagnostics remain in the result. A successful test body does not cancel the
cleanup budget.

Each retry has a new deadline and child scope. Tokens retained from a completed
attempt cannot acquire the next attempt's budget.

`afterTest()` subscribers run after this deadline scope closes. They cannot use
`AmpContext` operations.

## Limits

Cancellation is cooperative. A cancellation token requests that an operation
stop. It cannot interrupt blocking I/O, `sleep()`, or uninterrupted computation.
An operation can also ignore cancellation.

Cancellation of `Future::await()` stops that wait. It does not terminate the
producer. Registration through `AmpContext::async()` lets Greenlight wait for
the producer before it releases resources. For application-owned futures,
arrange producer cancellation and completion explicitly.

Native `Amp\async()`, direct Fibers, and callbacks do not inherit the bridge
context. Pass a token explicitly if they need the current deadline. Keep their
lifetimes within the attempt. Greenlight cannot find or stop detached work.

If a registered child ignores cancellation, Greenlight waits for it. With
process-pool execution, the existing process timeout can stop the worker.
In-process execution has no separate worker to stop. Use process-pool execution
for tests that can block. See [timeouts](attributes.md#timeout).

Without `AmpPlugin`, Greenlight uses its synchronous polling clock and elapsed
time checks.

## Integration basis

Amp provides native cancellation and future waits. Revolt provides the shared
scheduler. This combination lets the bridge use application scheduling without
a second Fiber scheduler. See the [Amp documentation](https://amphp.org/amp)
and [Revolt fundamentals](https://revolt.run/fundamentals).

The implementation follows Amp's source behavior for
[delay and child scheduling](https://github.com/amphp/amp/blob/v3.1.3/src/functions.php),
[future waits](https://github.com/amphp/amp/blob/v3.1.3/src/Future.php), and
[cancellation](https://github.com/amphp/amp/blob/v3.1.3/src/DeferredCancellation.php).
