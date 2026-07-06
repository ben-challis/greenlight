# Phase 1: core domain model

| | |
|---|---|
| **Track** | spine |
| **Unblocked by** | Phase 0 |
| **PRD sections** | 5 (authoring model), 7.1 (execution architecture), 8 (plugin context) |
| **Writes to** | `src/Core/`, `tests/`, `docs/rfcs/` |

## Goals

Define the value objects the entire system shares, before anything consumes them. This is the highest-leverage phase in the plan; every later component and the wire protocol are shaped by these types.

## Key tasks

- Attribute classes: `#[Test]`, `#[Before]`, `#[After]`, `#[DataSet]`, `#[Group]`, `#[Skip]`, `#[SkipUnless]`, `#[Retry]`, `#[Timeout]`, `#[Isolated]`.
- `TestId` (class + method + data-set key, stable and serialisable) and `TestMetadata`.
- The result model: `TestResult`, `Outcome` enum (passed/failed/errored/skipped/retried), `FailureDetail` with typed diff payloads.
- The event model: a closed set of run events as `readonly` classes (run/suite/class/test started and finished, worker spawned/recycled).
- Serialisation contracts for everything that will cross the process boundary: explicit `toWire()`/`fromWire()` arrays, no PHP `serialize()`.

## Deliverables

`src/Core/` complete with unit tests under the bootstrap runner; RFC-001 (result and event model) merged to `docs/rfcs/`.

## Design decisions

- Granularity of events: per-expectation events are rejected, too chatty for the wire.
- `TestResult` mutability during `afterTest` interception: immutable; plugins produce a replacement via a `withOutcome()` API that records provenance, per PRD section 8.
- Data-set keys in `TestId`: string keys, hashed if not printable.

## Dependencies

Phase 0 only.

## Risks

Over-modelling before real consumers exist. Mitigation: model only what the PRD names, mark everything `@internal` until Phase 7 freezes the plugin-visible subset.

## Validation

- Wire round-trip property tests (encode/decode equality) for every serialisable type.
- PHPStan max clean; deptrac shows `Core` depends on nothing.
