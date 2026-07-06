# Phase 11: memory, isolation, and long-running safeguards

| | |
|---|---|
| **Track** | convergence (one agent, after the fan-in; audits cross all components) |
| **Unblocked by** | everything through Phase 9 (doubles are the likeliest leak source and must exist to be gated) |
| **PRD sections** | 12 (memory and lifecycle principles) |
| **Writes to** | cross-cutting (audit changes in any component), `.github/workflows/`, `tests/` |

## Goals

Make PRD section 12 enforceable rather than aspirational.

## Key tasks

- `--detect-leaks` mode: a `WeakReference` per test instance, its doubles, and its per-test harness; named leak reports.
- Recycling policy tuning: test-count and memory-threshold triggers with hysteresis.
- The CI `memory` job: 10,000 synthetic tests, one worker, assert under 1 MB drift.
- A `WeakMap` audit of every cache in the codebase.
- A streaming audit of the orchestrator (bounded aggregates only).

## Deliverables

The memory CI gate, red until honest; leak-detection documentation.

## Design decisions

- What counts as drift: PHP-visible allocation (`memory_get_usage(true)`) for the gate; RSS recorded and graphed but not gated in v1.

## Dependencies

Everything through Phase 9.

## Risks

The gate flakes on GC timing. Mitigation: explicit `gc_collect_cycles()` at measurement points; drift measured as a regression line over the run rather than a single delta.

## Validation

- An intentionally leaky fixture test makes the gate fail; removing the leak makes it pass.
- `--detect-leaks` names the right test.
