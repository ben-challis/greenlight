# Temporal expectations

`Expect::eventually()` and `Expect::consistently()` apply an ordinary matcher to
values that a probe returns. Only the fluent API is public. Its poll support is
internal.

These `Expect` methods are the public construction interface. The constructors
and dependency-based creation methods are internal.

## Matcher operation

`TemporalExpectation::__call()` sends native and extension matcher calls to an
ordinary `Expectation`. The `@mixin Expectation<T>` declaration supplies the
native matcher types to PHPStan. The API-reference generator uses this
declaration to document the same methods on each public temporal return type.

Each poll creates an ordinary expectation for the probe value. It runs the
selected matcher without an increment to the expectation counter. The temporal
matcher increments the counter one time.

Custom matchers use the same `Expectation::__call()` path. An exception from
matcher code stops the poll operation. `eventually()` retries a probe exception
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
`eventually()` makes a final call at its deadline if no earlier call matches.
`consistently()` makes a final call at the end of its stability period.

The poll operation has no backoff or jitter. A fixed interval gives a
predictable schedule. It does not guarantee detection of states between probe
calls.

## Test timeouts

`TestExecutor` makes the current attempt's absolute monotonic deadline
available before it constructs the test. It clears the deadline after per-test
teardown. A temporal expectation uses the first applicable deadline. This
deadline is the earlier of its own deadline and the test deadline.

If the test deadline comes first, the failure includes the requested poll
duration. Greenlight cannot interrupt a blocked probe. Therefore, the
orchestrator process timeout remains the hard limit.

Each test retry has a new instance, scope, deadline, and observation log. The
first interrupt signal still lets active tests finish. This rule includes tests
that use a temporal expectation.

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
