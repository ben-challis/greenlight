# Phase 6: output capture and diagnostics

| | |
|---|---|
| **Track** | track E (small; good pipe-cleaner for the handoff process) |
| **Unblocked by** | RFC-003 |
| **PRD sections** | 7.3 (output capture) |
| **Writes to** | `src/Capture/`, `tests/` |

## Goals

Per-test capture of stdout, stderr, and PHP notices/warnings/deprecations, attached to results, never corrupting reporter streams.

## Key tasks

- Stream interception installed around test execution in the worker.
- Error-handler capture of notices/warnings/deprecations with configurable severity promotion (a deprecations-as-failures switch).
- Capture payloads on the wire and in `TestResult`.

## Deliverables

`src/Capture/`; escaped output from fixture tests appears in failure reports rather than in the TTY stream.

## Design decisions

- Capture buffers are bounded: default 1 MiB per stream per test, truncation is marked.
- Capture can be disabled per test: `#[Test(capture: false)]` for tests that debug output themselves.

## Dependencies

5a (worker), 5b (wire).

## Risks

Interaction with user code that also uses `ob_*`. Mitigation: document the nesting contract; an acceptance fixture nests output buffers.

## Validation

The chaos fixture suite from 5b now shows garbage-writing tests with clean reporter output.
