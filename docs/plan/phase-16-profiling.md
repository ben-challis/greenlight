# Phase 16: run profiling

| | |
|---|---|
| **Track** | post-GA, spine (small, serial; gates the performance phases) |
| **Unblocked by** | Phase 14 |
| **PRD sections** | 7.1 (architecture), 10 (output and reporting) |
| **Writes to** | `src/Cli/`, `src/Reporting/`, `tests/` |

## Goals

Before touching the scheduler or the spawn path we need to know what actually dominates a real run: worker idle time, spawn cost, or a few pathological classes. `--profile` turns the event stream the orchestrator already has into that answer. This phase exists so Phases 17 and 18 optimise measured problems instead of assumed ones.

## Key tasks

- A `--profile` flag that appends a profile block after the summary: per-worker busy versus idle time and utilisation percentage, spawn and recycle counts with total spawn wall time, the ten slowest classes with durations, and the makespan spread (fastest worker finish versus slowest, since that gap is exactly what better scheduling would recover).
- All of it is derived from existing events (worker spawned/recycled, class started/finished, test finished, run finished), computed by a pure aggregator over the stream. No new event types and no worker-side changes; if a number cannot be derived from the current stream, the answer is to add a timestamp field to an existing event, not a new message.
- The aggregator is a standalone class consumed by the reporters, so the jsonl stream plus the aggregator reproduce the same numbers offline: profiling a CI run means saving the jsonl artifact, no re-run needed.
- Document a baseline: run the self-hosted suite and a large generated fixture (the memory-gate generator already builds 10,000-test suites) with `--profile` and record the numbers in the phase summary, so Phases 17 and 18 have a before.

## Deliverables

`--profile` on the run command; a recorded baseline for the self-hosted suite and the 10,000-test synthetic suite; documentation in the configuration reference.

## Design decisions

- Derive, do not instrument. The event stream already timestamps everything the orchestrator needs; adding a metrics subsystem would be machinery without a user.
- Human-readable block on tty/plain only. Machine consumers already get the raw events via jsonl and can run the same aggregator.
- Utilisation is measured from the orchestrator's perspective (time between assignment and completion versus wall time). Worker-internal phases (autoload, discovery of the config file) show up as spawn cost, which is the granularity Phase 18 needs.

## Dependencies

Phase 14 only to avoid churn in the summary rendering both phases touch.

## Risks

The numbers mislead if event timestamps are taken at different points than assumed (send time versus receive time under buffering). Mitigation: timestamps are worker-side event creation times already carried on the wire; the aggregator documents which timestamp each metric uses.

## Recorded baseline (self-hosted suite, 407 tests)

Measured at the phase's completion, workers=4 on an 11-core machine:

- Boot latency: 0.125s average, spawn to first class.
- Utilisation: 77% to 98% per worker, but the busy totals expose the static-bucket problem: one worker carried 6.6s of the 13.2s total busy time (ParallelRunTest alone is 4.9s) while another finished its whole bucket in 0.4s.
- Makespan spread: 6.2s between the first and last worker finish. This spread is the headroom Phase 17's pull scheduling and longest-first ordering exist to recover; acceptance-test classes that shell out to the CLI dominate the slow list.

The 10,000-test synthetic baseline is deferred to Phase 17's before/after measurement, where the same generator run feeds both sides of the comparison; the memory gate's own run is single-worker by design and profiles nothing useful.

## Validation

- Unit: the aggregator over a canned event stream with known gaps asserts exact busy/idle/utilisation numbers, including recycling mid-run and an idle worker.
- Acceptance: `--profile` on a fixture run prints the block, and the same numbers come out of the jsonl artifact fed to the aggregator.
