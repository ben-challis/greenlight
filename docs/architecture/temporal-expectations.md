# Temporal expectation architecture

This note records the timing and integration constraints behind
`Expect::eventually()` and `Expect::consistently()`. The fluent API is public;
the clock, observation, and runner-context types are internal.

## Matcher reuse

Temporal expectations expose the same typed native matcher surface as an
ordinary `Expectation`. Each observation constructs an ordinary expectation
for the sampled value and invokes that matcher with expectation counting
suppressed. The temporal operation increments the worker-local counter once.

Configured extension matchers take the same path through `Expectation::__call`.
Exceptions thrown by matcher code escape immediately. Only exceptions thrown
by the probe and explicitly listed with `retryOnException()` can become failed
observations.

After a temporal matcher passes, it returns an ordinary `Expectation` anchored
to the final value. Consequently, chained matchers inspect one snapshot and
cannot independently pass against different points in time.

## Time model

All temporal scheduling uses a monotonic clock. Production time comes from
`hrtime(true)`; tests supply a fake clock and advance it when the scheduler
sleeps.

Both modes make their first observation immediately and poll sequentially with
a fixed delay after each completed probe. They never overlap probes.

`eventually()` anchors its deadline before the first probe and succeeds on the
first matching observation completed by that deadline. `consistently()` first
requires a successful observation, then anchors its stability period and fails
on the first violation. Both schedule a final observation at the boundary.

Backoff and jitter are deliberately absent from the public API. Fixed intervals
are deterministic and do not skip brief observable states. The scheduler is an
internal seam, so another policy can be added later without changing matchers.

## Nested timeout

`TestExecutor` installs the current attempt's absolute monotonic deadline before
test construction and removes it after per-test teardown. A temporal
expectation chooses the earlier of that deadline and its own requested
deadline.

When the test deadline wins, the temporal failure names both constraints.
Retries create a new instance, scope, attempt deadline, and observation
history. A blocking probe is not preemptible; orchestrator-side process
termination remains the hard timeout.

Graceful interruption is unchanged. The first signal drains after in-flight
tests, including active temporal expectations.

## Failure retention

Only the current live sample is retained. Failure history contains rendered,
UTF-8-safe strings: the first group, the last three groups, repeat counts,
elapsed offsets, total observation count, and an omitted-group count.
Rendered history is capped at 2 KiB.

The final matcher failure supplies the ordinary expected and actual fields, so
plain, TTY, GitHub, JUnit, TeamCity, and JSONL reporters keep their existing
diff behaviour. The observation summary is appended to the failure message.
No wire field or protocol version is added.
