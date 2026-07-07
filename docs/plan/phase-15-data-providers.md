# Phase 15: provider-method data sets

| | |
|---|---|
| **Track** | post-1.0, track B follow-on (serial: touches the discovery/execution seam) |
| **Unblocked by** | Phase 14 (filter semantics for data-set labels must be settled first) |
| **PRD sections** | 5.1 (shape of a test), 5.3 (flow-control attributes) |
| **Writes to** | `src/Attribute/`, `src/Core/`, `src/Discovery/`, `src/Runner/Worker/`, `tests/` |

## Goals

`#[DataSet]` today carries literal values in the attribute, which covers scalars and arrays but not computed cases: objects, fixture files, ranges, or anything built at runtime. A provider form closes that: `#[DataSetFrom('casesMethod')]` names a static method on the test class whose return value supplies the rows.

## Key tasks

- A `#[DataSetFrom(string $method)]` attribute, repeatable and combinable with literal `#[DataSet]` rows on the same test. The named method must be static, public, parameterless, and declared on the test class itself (no inheritance lookup in the first iteration), returning `iterable<array<mixed>>` or `iterable<string, array<mixed>>`; string keys become row labels exactly as literal data-set labels do.
- Plan-time expansion is the hard decision and it stays: discovery invokes the provider once, at plan build, in the orchestrator process. Row counts and labels are then stable for distribution, filtering, and the summary cross-check, exactly like literal rows. A provider that throws fails discovery with the class, method, and cause named.
- The wire consequence: provider rows can contain objects, and plan entries cross the wire to workers, so entries carry the row index rather than the row values. Workers re-invoke the provider locally and pick their row by index. Determinism contract: providers must yield the same count and labels in every process; the worker validates count and label against the plan entry and errors the test on mismatch rather than running with the wrong data.
- Provider results are cached per class per process, so a class with many provider-fed tests invokes each provider once, not once per row.
- Metadata surfaces the provenance (`literal` or `provider(method)`) so reporters and plugins can render it.

## Deliverables

Provider-fed data sets running under parallel execution and `--filter`, with object-valued rows proven by a fixture; attribute reference and migration guide updated (this is the PHPUnit `@dataProvider` landing point).

## Design decisions

- Plan-time expansion with worker-side re-invocation, rather than shipping values over the wire. Values may be arbitrary objects and the wire contract deliberately refuses PHP serialisation; the index-plus-revalidation scheme keeps the wire JSON-safe and the plan deterministic while allowing rich values.
- Static, parameterless, same-class providers only. Instance providers would need a constructed test instance at discovery time, which breaks the flat-memory model; cross-class providers add a lookup surface with little payoff. Both can relax later without breaking anything shipped.
- Combinable with literal rows because migration guides say "start by moving simple cases"; forcing an all-or-nothing switch per test would make adoption harder.

## Dependencies

Phase 14's filter matcher must already handle data-set labels so provider labels filter identically.

## Risks

Non-deterministic providers (random data, time-dependent cases) silently changing labels between orchestrator and worker. The count-and-label validation turns that into a loud per-test error with a message naming the provider, which is the best failure mode available without banning generated data outright.

## Validation

- Unit: discovery expansion (counts, labels, provenance), provider error paths (missing method, non-static, wrong return shape, throwing provider), cache behaviour.
- Acceptance: a fixture mixing literal and provider rows including object values, run at workers=4, asserting per-row results and the summary cross-check; a deliberately non-deterministic provider fixture asserting the mismatch error.
