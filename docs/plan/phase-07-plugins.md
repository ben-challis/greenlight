# Phase 7: plugin architecture and execution context

| | |
|---|---|
| **Track** | spine |
| **Unblocked by** | Phases 5b and 6 |
| **PRD sections** | 8 (plugin architecture), 9 (harness) |
| **Writes to** | `src/Plugin/`, `tests/`, `docs/rfcs/` |

## Goals

The PRD section 8 API: typed subscriber interfaces, live `TestContext`, capability-scoped plugin types, orchestrator/worker side documentation, provenance-logged outcome transformation.

## Key tasks

- `TestContext` assembly in the worker.
- Subscriber discovery from implemented interfaces; plugin registration via config.
- `HarnessProvider` wiring into the scope container.
- `ExpectationExtension` wiring into `Expect`.
- Internal refactor: `#[Retry]` and `#[Skip]` re-implemented as internal plugins to prove the API carries real weight.

## Deliverables

`src/Plugin/`; RFC-004 (the semver-scoped public plugin API surface); at least two internal features shipped as plugins.

## Design decisions

- Exactly which Phase 1 types are plugin-visible; everything else stays `@internal`. Promotion out of `@internal` happens only here (and is finalised in Phase 13); no other phase removes the annotation from anything.
- Subscriber ordering: priority integer, stable sort, documented.
- Error policy when a plugin throws: fail the test with plugin attribution, never swallow.

## Dependencies

5a, 5b, 6. The API is declared usable here but stable only in Phase 13, after two phases of internal dogfooding, per the PRD.

## Risks

Freezing too early. Mitigation: an `@experimental` annotation on the whole surface until Phase 13; CHANGELOG discipline for any change.

## Validation

The PRD's acceptance test, started now: a flaky-quarantine plugin built in `tests/Fixture/plugins/` using only the public API.
