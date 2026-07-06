# Phase 5b: orchestrator, wire protocol, parallel pool

| | |
|---|---|
| **Track** | spine (highest-risk phase; schedule nothing else against these interfaces while it lands) |
| **Unblocked by** | Phase 5a; RFC-003 (wire protocol) agreed before implementation |
| **PRD sections** | 7 (execution model), 12 (memory principles) |
| **Writes to** | `src/Runner/Orchestrator/`, `src/Runner/Protocol/`, `tests/`, `docs/rfcs/` |

## Goals

The parallel-first runner from PRD section 7: process pool, binary-framed socket protocol, deterministic distribution, recycling, crash containment.

## Key tasks

- Process spawn/manage abstraction (owned, no symfony/process).
- Framed protocol carrying Phase 1 wire types.
- Deterministic distribution by class-name hash plus optional timing cache (`.greenlight/timings`).
- Worker recycling on test-count and memory thresholds.
- `--bail` draining; `#[Isolated]` via dedicated worker.
- Segfault/fatal containment and reporting.

## Deliverables

`src/Runner/Orchestrator/`, `src/Runner/Protocol/`; parallel runs are the default. RFC-003 merged before implementation starts.

## Design decisions

- Protocol framing: length-prefixed JSON at first, with the encoding swappable behind an interface; measure before optimising to a binary encoding.
- Windows story: no `pcntl` required; `proc_open` worker spawning works everywhere, with a localhost TCP fallback for sockets.

## Dependencies

5a complete and RFC-003 agreed.

## Risks

The PRD names this the main technical risk of the whole project. Mitigations: property-based round-trip tests on the protocol; a chaos fixture suite (tests that exit, segfault via ffi, leak, write garbage to stdout) pins containment behaviour.

## Validation

- The same fixture suite produces identical aggregated results at `--workers=1`, `4`, and `16`.
- kill -9 on a worker mid-run yields a failed-test attribution and a completed run.
