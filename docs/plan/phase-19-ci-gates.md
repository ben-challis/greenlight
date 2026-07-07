# Phase 19: CI gates and sharding

| | |
|---|---|
| **Track** | post-GA, track J (parallelisable: sharding, policy flags, and risky-test detection are independent) |
| **Unblocked by** | Phase 17 (sharding composes with the queue), Phase 14 (summary rendering) |
| **PRD sections** | 7.2 (flow control), 10 (output and reporting), 16 (success metrics) |
| **Writes to** | `src/Cli/`, `src/Discovery/`, `src/Expect/`, `src/Runner/`, `tests/` |

## Goals

The features CI pipelines ask of a runner: split one suite across machines, fail the build on deprecations or notices instead of merely recording them, and flag tests that assert nothing.

## Key tasks

- `--shard=<n>/<m>` runs the nth of m equal slices of the plan. Sharding selects whole classes deterministically from the seeded plan (stable hash modulo m, the same trick the old distributor used), so the union of all shards is exactly the full suite and no coordination between machines is needed. Shard selection happens before scheduling; within a shard, Phase 17 scheduling applies as normal. `--shard` combines with `--group` and `--filter` by sharding the filtered plan.
- Policy flags `--fail-on-deprecation` and `--fail-on-notice` (and the matching config builder methods, CLI overriding file as always). Capture already attaches deprecations and notices to each result; the policy marks affected passed tests as failed at result level with the captured diagnostic as the failure detail, so reporters, `--bail`, and the exit code all follow without special cases. An allow-list method on the builder (`ignoreDeprecationsMatching(pattern)`) covers the dependency-noise reality of real projects.
- Zero-expectation detection. `Expect` counts verified expectations per test (the per-test registry from `DefaultServices` gives the natural place to accumulate), and doubles verification counts too, since a test whose only assertions are mock expectations is not risky. A passed test with a zero count gets outcome metadata marking it risky; default behaviour is a summary line naming risky tests, `--fail-on-risky` upgrades them to failures. Tests that legitimately assert nothing (smoke tests that pass by not throwing) opt out with a `#[NoExpectations]` attribute stating the intent explicitly.

## Deliverables

Sharding proven by an acceptance test that runs all shards and reconstitutes the full suite exactly once; policy flags and risky detection working on the self-hosted suite (which must itself come out clean or be fixed); docs and changelog updated.

## Design decisions

- Shards select classes, not methods, because class-level hooks and per-class fixtures make a class the smallest unit that relocates between machines safely, the same reasoning the distributor always used.
- Deterministic hash sharding over recorded-timing balanced sharding for the first iteration: correctness and zero coordination first. The timing cache can inform balanced sharding later without changing the flag's contract.
- Policy failures are result-level transformations, not reporter-level rendering, so every consumer (exit code, bail, junit, plugins) sees the same truth. The provenance mechanism from the plugin API records that the policy, not the test body, flipped the outcome.
- Risky detection counts expectations rather than inspecting test bodies. Static inspection lies (helpers, custom assertion services); the runtime count is ground truth in exchange for one integer per test.

## Dependencies

Phase 17's queue (sharding slots in ahead of it), Phase 14's summary layout. Neither is conceptually required, but sequencing avoids rework in the same files.

## Risks

Risky-test false positives in suites with custom assertion helpers that bypass `Expect`. Mitigation: the count hooks into the failure-sink seam every expectation already flows through, extensions and soft mode included, and `#[NoExpectations]` plus the default-warn (not default-fail) behaviour keep the feature honest while trust builds.

## Validation

- Sharding: property-style acceptance test asserting the shards partition the plan (disjoint, union complete) across several m values and seeds.
- Policy flags: fixtures emitting a deprecation and a notice, asserting pass without the flag, failure detail and exit code with it, and the allow-list exempting a matched pattern.
- Risky: fixtures for a zero-expectation pass (warned, then failed under the flag), a doubles-only test (not risky), and an opted-out test (silent).
