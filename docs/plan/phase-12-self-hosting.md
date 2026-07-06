# Phase 12: self-hosting cutover

| | |
|---|---|
| **Track** | integration (serial) |
| **Unblocked by** | Phases 4, 5b, 6, 8 minimum (a runner, expectations, capture, at least the plain reporter) |
| **PRD sections** | 13 (self-testing principle), 15 (Phase 1 exit criterion) |
| **Writes to** | `composer.json`, `tools/`, `tests/`, `.github/workflows/` |

## Goals

Greenlight's suite runs under `bin/greenlight`; the bootstrap runner is deleted.

## Key tasks

- Port any remaining bootstrap-runner-isms in the test suite.
- Redefine `composer tests` to `bin/greenlight run`.
- Run the suite in parallel in CI with coverage.
- Delete `tools/bootstrap-runner.php`.

## Deliverables

A framework that tests itself in parallel with flat memory in CI: the PRD's Phase 1 exit criterion, fully realised.

## Design decisions

None new; this phase exists to force honesty. Doubles and coverage improve it but do not gate it; in practice self-hosting lands incrementally from Phase 5b onward and this phase is the formal cutover.

## Risks

Circularity in debugging: a runner bug breaks the tests that would find it. Mitigation: the acceptance-test layer runs fixture suites as subprocesses and asserts on observable output, so a broken runner fails loudly rather than reporting green.

## Validation

- CI green with `composer tests` invoking `bin/greenlight`.
- A deliberately broken matcher causes a red build (mutation-style spot check, manual).
