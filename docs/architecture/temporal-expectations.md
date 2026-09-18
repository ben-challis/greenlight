# Temporal expectations

`Expect::calling()` creates a lazy call expectation. `returnValue()` selects
its return value. `eventually()` and `consistently()` select a poll operation.
Only the fluent interface is public. Constructors and poll support are internal.

## Matcher operation

`ValueMatchers` declares native value matcher methods for `Expectation` and
`TemporalExpectation`. These methods are visible through PHP reflection.
Their native signatures and generic PHPDoc types do not require a PHPStan
method-reflection extension. `__call()` dispatches custom extension matchers only.

`CallExpectation` and `TemporalCallExpectation` expose call matchers.
Value expectations do not expose `toThrow()`. `CallOutcome` preserves one
invocation, including its exact throwable or null return value.

The internal `MatcherEvaluation` applies each matcher and creates diagnostics.
Each poll evaluates one captured subject. The matcher does not increment the
expectation counter during a poll. The temporal matcher increments it once.

An `ExpectationFailed` from matcher code records a mismatch. `eventually()`
continues after a mismatch, while `consistently()` fails. Other exceptions from
matcher code stop the poll operation. A return-value poll retries a probe
exception only if `retryOnException()` lists its type.

A temporal call matcher captures the invocation before it checks the outcome.
`toThrow()` therefore observes exceptions without a callback that returns
another callback. Constraint validation occurs before the first call.

A successful temporal value matcher returns an ordinary `Expectation` for the
last value. A successful temporal call matcher returns a `CallExpectation`
for the last outcome. Later matchers inspect that value or outcome without
another invocation.

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
teardown. A temporal expectation uses the first applicable deadline. This
deadline is the earlier of its own deadline and the test deadline.

If the test deadline comes first, the failure includes the requested poll
duration. Greenlight cannot interrupt a blocked probe. Therefore, the
process-pool orchestrator timeout remains the hard limit. An in-process run
cannot forcibly stop a blocked probe.

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
