# Phase 4: assertion model (Expect)

| | |
|---|---|
| **Track** | track C (parallel with Phases 2 and 3; longest track, start first) |
| **Unblocked by** | RFC-001 |
| **PRD sections** | 5.2 (expectations) |
| **Writes to** | `src/Expect/`, `tests/` |

## Goals

The injected `Expect` service, core matcher set, diff rendering, soft expectations. Usable standalone.

## Key tasks

- `Expect::that()` chain and core matchers: equality, identity, type, exceptions, iterables, strings, numerics with delta.
- Typed value renderers and diffing.
- `softly()` for collected multi-failures.
- Failure objects feeding `FailureDetail` from Phase 1.
- The `ExpectationExtension` registration seam (interface only; plugin wiring arrives in Phase 7).

## Deliverables

`src/Expect/`. From this phase on, Greenlight's own tests use `Expect` instead of `assert()`.

## Design decisions

- Failure message format: expectation sentence plus typed diff, no PHPUnit-style constraint trees.
- How `and()` re-anchors the subject.
- Negation is a `not()` modifier rather than paired matchers, halving the matcher count.

## Dependencies

Phase 1 only. Fully parallel with Phases 2 and 3.

## Risks

Diff quality is unbounded scope. Mitigation: v1 diffs cover scalars, arrays, enums, DateTime, and plain objects by reflection; everything else falls back to a documented export format, and better renderers are expectation-plugin territory.

## Validation

- Matcher spec suite: every matcher has a pass case, a fail case, and a message snapshot.
- Self-hosted usage across the repo's own tests.
