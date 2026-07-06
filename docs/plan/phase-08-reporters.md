# Phase 8: reporters and output formats

| | |
|---|---|
| **Track** | track F (each reporter is independent; one agent per reporter is safe) |
| **Unblocked by** | RFC-003 (frozen event stream) |
| **PRD sections** | 10 (output and reporting) |
| **Writes to** | `src/Reporting/`, `tests/`, `docs/architecture/` |

## Goals

`tty`, `plain`, `junit` first; `jsonl`, `github`, `teamcity` second wave. All render from the identical result stream.

## Key tasks

- `Reporter` consumption of the orchestrator event stream.
- TTY renderer: live per-worker progress, failure diffs, captured output, slowest-tests and memory summaries.
- Deterministic plain renderer.
- JUnit XML writer.
- JSONL schema, documented in `docs/architecture/jsonl.md` and versioned.
- GitHub Actions annotations; TeamCity service messages.

## Deliverables

`src/Reporting/` plus one doc per format.

## Design decisions

- JSONL schema versioning: an explicit `"v": 1` field, additive changes only.
- TTY terminal capability detection is owned and small; ANSI handling is bounded to what the renderer uses.

## Dependencies

Phase 1 event model (frozen) and 5b streaming. Reporters share only the read-only event stream and the golden-test harness, so multiple agents can build reporters without merge conflicts.

## Risks

TTY rendering is a scope sink. Mitigation: the TTY reporter gets a budget (no themes, no animations in v1) and everything fancy is plugin territory by construction.

## Validation

- Golden-file tests per reporter against a canned event stream.
- JUnit output validated against the de facto schema.
- PhpStorm consumes the TeamCity stream (manual checklist).
