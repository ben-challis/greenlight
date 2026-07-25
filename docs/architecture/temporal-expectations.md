# Temporal expectations

`Expect::eventually()` and `Expect::consistently()` apply an ordinary matcher to
values returned by a probe. Only the fluent API is public; its polling support
is internal.

## Running matchers

`TemporalExpectation` has the same native matcher methods as `Expectation`.
Each poll creates an ordinary expectation for the probe's value and runs the
selected matcher without incrementing the expectation counter. The polling
matcher increments the counter once.

Custom matchers use the same `Expectation::__call()` path. An exception from
matcher code stops polling. `eventually()` only retries exceptions thrown by
the probe when their types are listed with `retryOnException()`.

A successful polling matcher returns an ordinary `Expectation` for the last
value. Any matcher chained after it checks that value once.

## Polling

Polling uses a monotonic clock. `SystemPollingClock` reads `hrtime(true)` and
sleeps with `usleep()`. Unit tests use a fake clock.

Both methods call the probe immediately, then wait for the configured fixed
interval before calling it again. Probe calls never overlap.

`eventually()` sets its deadline before the first call and returns after the
first match. `consistently()` requires its first call to match, starts its
stability period after that call, and fails on the first mismatch.
`eventually()` makes a final call at its deadline if no earlier call matches.
`consistently()` makes a final call at the end of its stability period.

Polling has no backoff or jitter. A fixed interval makes the schedule
predictable and avoids skipping short-lived states.

## Test timeouts

`TestExecutor` makes the current attempt's absolute monotonic deadline
available before it constructs the test. It clears the deadline after per-test
teardown. A polling expectation uses whichever deadline comes first: its own or
the test's.

If the test deadline comes first, the failure includes the requested polling
duration. Greenlight cannot interrupt a probe that blocks, so the
orchestrator's process timeout remains the hard limit.

Each test retry has a new instance, scope, deadline, and observation log. The
first interrupt signal still lets tests in flight finish, including tests that
are polling.

## Failures

`ObservationLog` stores rendered strings instead of retaining every value. It
keeps the first group and the last three groups, combines repeated values, and
records elapsed time and the number of omitted groups. The rendered log is
limited to 2 KiB.

The final failure keeps the matcher's expected and actual values, so existing
reporters render their usual diff. Greenlight appends the observation log to
the failure message. The wire format does not change.
