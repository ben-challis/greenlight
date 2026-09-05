# Temporal expectations

`Expect::eventually()` and `Expect::consistently()` apply an ordinary matcher to
values that a probe returns. Only the fluent API is public. Its poll support is
internal.

These `Expect` methods are the public construction interface. The constructors
and dependency-based creation methods are internal.

## Matcher operation

`TemporalExpectation::__call()` sends each native or extension matcher to an
ordinary `Expectation`. Each poll creates this ordinary expectation for the
probe value. The matcher runs without an increment to the expectation counter.
The temporal matcher increments the counter one time.

The `@mixin Expectation<T>` declaration supplies the native matcher methods to
the API-reference generator.

Native matcher methods are not reflection-visible on `TemporalExpectation`.
This is an intentional interface change. Code that reflects matcher methods
MUST use `Expectation` as its source.

The PHPStan extension supplies the native methods on temporal chains. The IDE
helper supplies the same methods as annotations. Thus, normal temporal matcher
syntax keeps its static signatures in these tools.

An `ExpectationFailed` from matcher code records a mismatch. `eventually()`
continues after a mismatch, while `consistently()` fails. Other exceptions from
matcher code stop the poll operation. `eventually()` retries a probe exception
only if `retryOnException()` lists its type.

A successful temporal matcher returns an ordinary `Expectation` for the last
value. Each matcher after it checks that value one time.

## Poll operation

The poll operation uses a monotonic clock. `SystemPollingClock` reads
`hrtime(true)` and waits with `usleep()`. Unit tests use `FakePollingClock`.

The default poll interval is 25ms. `pollEvery()` accepts finite intervals of at
least 1ms. A duration for `within()` or `for()` **MUST** be finite and more
than zero.

Both methods call the probe immediately. They then wait for the configured fixed
interval and call the probe again. Probe calls never overlap.

`eventually()` sets its deadline before the first call and returns after the
first match. `consistently()` requires its first call to match, starts its
stability period after that call, and fails on the first mismatch.
The next wait ends at the applicable deadline if a full interval would exceed
it. Greenlight then calls the probe again. An earlier probe call that reaches
or exceeds the deadline can end the operation without another call.

The poll operation has no backoff or jitter. A fixed interval gives a
predictable schedule. It does not guarantee detection of states between probe
calls.

## Test timeouts

`TestExecutor` makes the current attempt's absolute monotonic deadline
available before it constructs the test. It clears the deadline after per-test
teardown. Each temporal matcher resolves the active deadlines when it runs.
This rule also applies to an expectation constructed before the matcher call.

A temporal expectation uses the earliest applicable deadline from these sources:

* Its own wait or observation period
* An enclosing temporal expectation
* The current test attempt

The enclosing deadline applies to nested expectations in probes and matchers.
For `consistently()`, the first observation uses only the inherited deadlines.
Its own observation period starts after the first successful observation.

Each Fiber has a separate enclosing deadline scope. Main execution has its own
scope. A new Fiber does not inherit another Fiber's enclosing deadline.
The test deadline applies to all Fibers in the attempt. Each observation restores
its previous scope, including when it throws. Each attempt starts with empty scopes.

If an inherited deadline comes first, the failure identifies the test or
enclosing expectation and includes the requested duration. A test deadline takes
precedence when both inherited deadlines are equal.

Deadline scopes do not schedule or interrupt Fibers. Greenlight cannot interrupt
a blocked probe. For tests with a configured timeout, the process-pool orchestrator
enforces a separate process limit. An in-process run cannot forcibly stop a blocked probe.

Each test retry has a new instance, scope, deadline, and observation log. With
`ext-pcntl` available, the first interrupt signal still lets active tests
finish. This rule includes tests that use a temporal expectation. Without
PCNTL, the operating system's default immediate termination behavior can stop
the active test.

`retryOnException()` accepts only `Exception` subclasses, which excludes
`Error`, `Throwable`, and other broader types.

## Failures

`ObservationLog` stores rendered strings instead of every value. It keeps the
first group and the last three groups. It combines repeated values. It also
records elapsed time and the number of omitted groups. The rendered log has a
2 KiB limit.

The final failure keeps the matcher's expected and actual values. Thus, current
reporters render their usual difference. Greenlight adds the observation log
to the failure message. The wire format does not change.
