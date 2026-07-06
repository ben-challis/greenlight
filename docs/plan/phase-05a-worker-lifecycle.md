# Phase 5a: worker runtime and lifecycle

| | |
|---|---|
| **Track** | spine |
| **Unblocked by** | Phases 2, 3, 4; RFC-002 (lifecycle and scopes) written before implementation |
| **PRD sections** | 5 (authoring), 7.1 step 3 (execution), 9 (harness) |
| **Writes to** | `src/Harness/`, `src/Runner/Worker/`, `tests/`, `docs/rfcs/` |

## Goals

Run one plan slice correctly in one process: instantiate the test class with constructor injection, resolve harness scopes, run hooks, run the test, tear down deterministically, emit Phase 1 events. No parallelism yet.

## Key tasks

- The harness scope container: `perTest`/`perClass`/`perSuite`/`perRun`, reverse-order `Disposable` teardown, lazy-object instantiation.
- Test class instantiation and injection resolution.
- Hook execution with defined `#[Before]`/`#[After]` ordering rules.
- `#[Skip]`/`#[SkipUnless]`/`#[Timeout]`/`#[Retry]` semantics.
- The in-worker event emitter.

## Deliverables

`src/Harness/`, `src/Runner/Worker/`; `bin/greenlight run --workers=1` executes fixture suites end to end. RFC-002 merged.

## Design decisions

- Injection resolution: exact type match only, no autowiring of arbitrary classes; an unknown constructor parameter is a hard error naming the type.
- Timeout mechanism in-worker: cooperative `hrtime` checks, plus an orchestrator-side hard kill later in 5b. `pcntl_alarm` is rejected as signal-unsafe with user code.

## Dependencies

Phases 1, 2, 3; Phase 4 for its own tests.

## Risks

This phase defines lifecycle semantics that the plugin API (Phase 7) exposes; getting teardown ordering wrong here is expensive later. Mitigation: RFC-002 before implementation; exhaustive ordering tests including failure-during-teardown cases.

## Validation

Lifecycle trace tests: fixture suites record every construct/hook/test/dispose call, with assertions on exact order including failure paths.
