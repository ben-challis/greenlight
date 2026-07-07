# Phase 17: demand-driven scheduling and the timing cache

| | |
|---|---|
| **Track** | post-GA, spine (serial: rewires the orchestrator/distributor seam) |
| **Unblocked by** | Phase 16 (the baseline decides how far to go) |
| **PRD sections** | 7.1 (architecture), 7.2 (flow control) |
| **Writes to** | `src/Runner/Orchestrator/`, `src/Runner/Protocol/`, `tests/` |

## Goals

Today the distributor buckets whole classes by `crc32(class) % workers` before anything runs, so makespan is set by whichever bucket happens to receive the slow classes and every other worker idles at the tail. Two changes fix that: workers pull the next class from an orchestrator-held queue as they finish (no more static buckets), and a persisted timing cache orders that queue longest-first so the big rocks are placed first.

## Key tasks

- Queue-based assignment. The orchestrator holds one ordered queue of class units (an isolated entry remains its own unit with a dedicated fresh worker, unchanged). A worker gets one class per assignment and requests the next on completion. The existing protocol already frames assignment and completion; the change is orchestration state, not message vocabulary, though assignment payloads shrink from slice to class.
- Determinism is redefined, deliberately. Placement (which worker runs which class) becomes load-dependent; what stays deterministic is everything correctness depends on: the queue order for a given seed and cache state, within-class method order under the seed, and per-class results. The seed reproduces failures, not worker placement. This is written into the docs as the contract.
- Crash containment and the summary cross-check adapt from slice bookkeeping to per-class bookkeeping: a crashed worker forfeits exactly its in-flight class, the erroring rules are unchanged, and the spawn budget formula is revisited since assignment counts now scale with classes rather than slices.
- The timing cache. Every run records per-class wall durations (from existing events) to a state file under the temp dir keyed by the working-directory hash, alongside the Phase 14 failure state. On the next run the queue orders by recorded duration descending (classic longest-processing-time scheduling), with unknown classes ordered after known ones by the seeded order. `--seed` runs skip cache ordering entirely so the randomized order stays honest.
- Failed-first from Phase 14 composes on top: previously failed classes go first regardless of duration, then LPT ordering applies. Fast feedback beats optimal packing.
- Out of scope, recorded as such: splitting one giant class's data-set rows across workers. It requires per-worker before/after-class hook re-runs and shared-fixture semantics that deserve their own decision. The profile output from Phase 16 will show whether any real suite needs it.

## Deliverables

Queue-based assignment on by default, timing cache written and consumed, Phase 16 baseline re-measured and the delta recorded; docs updated with the determinism contract.

## Design decisions

- Pull model over smarter static buckets. Static assignment cannot beat pull scheduling without perfect duration knowledge, and the timing cache is a hint, not a promise: durations drift with code changes. Pull absorbs drift automatically.
- The cache is advisory and disposable. A missing or stale cache costs packing quality, never correctness; nothing validates it beyond ignoring entries for classes no longer in the plan.
- LPT plus pull rather than either alone: pull without ordering still strands a slow class at the tail if it dequeues last; ordering without pull cannot react to drift.
- Seeded runs bypass the cache because a randomized order that a hidden cache silently rewrites is a debugging trap.

## Dependencies

Phase 16's baseline, both to justify the work and to prove the delta. Phase 14's failure state file establishes the temp-dir state convention this reuses.

## Risks

This rewires the most intricate code in the project (crash containment, drain, recycling, the summary cross-check were all hardened against real bugs). Mitigation: the existing orchestrator acceptance suite runs unchanged and green before and after; the cross-check stays on; the old distributor is deleted only at the end of the phase, not the start.

## Measured delta (self-hosted suite, 407 tests at the time, workers=4)

Baseline from Phase 16: 6.204s makespan spread, one static bucket holding 6.6s of busy time while another held 0.4s. After this phase:

- Pull scheduling alone (cold cache): 2.6s spread, every worker at 95% utilisation or better, per-worker class counts of 2/3/31/42 showing the queue balancing load dynamically.
- With the warm timing cache: 2.0s spread. The slowest class (ParallelRunTest, 4.8s) is assigned first to a worker that runs nothing else, and one worker mops up 71 fast classes. The remaining spread is the floor set by that single indivisible class, which is exactly the case the deferred giant-class splitting would address.

## Validation

- Unit: queue ordering (LPT, failed-first composition, seeded bypass, unknown classes), per-class containment bookkeeping, spawn budget under the new formula.
- Acceptance: a fixture with one deliberately slow class and workers=4 finishes measurably faster than the recorded Phase 16 baseline shape (assert on assignment behaviour, not wall clock: the slow class is assigned first once the cache knows it, and no worker receives a second class while another has received none).
- The full self-hosted suite at multiple worker counts, plus the crash and recycling fixtures, all green with the cross-check active.
