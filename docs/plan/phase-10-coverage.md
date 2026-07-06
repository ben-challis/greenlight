# Phase 10: coverage collection and export

| | |
|---|---|
| **Track** | track G (independent of 7, 8, 9) |
| **Unblocked by** | RFC-003 |
| **PRD sections** | 11 (coverage) |
| **Writes to** | `src/Coverage/`, `tests/` |

## Goals

pcov and Xdebug drivers, per-worker collection with incremental orchestrator merge, lcov/Clover/Cobertura/HTML/JSON exports, baseline diff.

## Key tasks

- Driver abstraction and detection.
- Per-worker collection windows around test execution.
- Merge on the orchestrator as results stream, avoiding an end-of-run spike.
- The five exporters.
- The `coverage:diff` command.
- Opt-in per-test coverage mapping.

## Deliverables

`src/Coverage/`; a CI coverage badge for Greenlight's own repository.

## Design decisions

- Merge data structure: bitsets per file keyed by path hash, benchmarked against a 1M-line fixture.
- HTML report scope: static output, no JS framework, one page per file plus an index.

## Dependencies

5b (workers and wire).

## Risks

Xdebug branch/path coverage wire volume. Mitigation: branch data batched per class rather than per test; measured before optimised.

## Validation

- Coverage of a fixture project matches reference numbers within documented semantics.
- lcov output is consumed by `genhtml` without warnings.
