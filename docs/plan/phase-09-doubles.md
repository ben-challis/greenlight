# Phase 9: test doubles

| | |
|---|---|
| **Track** | track D (independent of 5b, 6, 7, 8) |
| **Unblocked by** | RFC-002 (needs the teardown hook signature) plus Phase 4 |
| **PRD sections** | 14 (mocking and test doubles), 12 (memory principles) |
| **Writes to** | `src/Doubles/`, `tests/` |

## Goals

`greenlight/doubles` per PRD section 14: mock/stub/spy/fake, strict by default, lazy-object based, auto-verified and auto-disposed at test end. Usable standalone with `Expect`.

## Key tasks

- Proxy generation for interfaces and non-final classes, cached per worker, invalidated by signature hash.
- The `MockPlan` expectation DSL.
- Argument matching integrated with `Expect` matchers.
- `Doubles` factory as a per-test harness service; auto-verify hooked into test teardown.
- Spy assertion bridge (`toHaveReceived`); `Fake` marker interface.

## Deliverables

`src/Doubles/`.

## Design decisions

- Code generation strategy: generated classes written to `.greenlight/proxies/` (opcache benefit, debuggability) rather than eval'd.
- Intersection/union type handling in generated signatures.
- Readonly class doubling is out of scope for v1, documented.

## Dependencies

Phase 4 (Expect integration points) and 5a (per-test scope and teardown hook). Explicitly does not need the orchestrator.

## Risks

The PRD's named highest-effort component. Scope containment is contractual: interfaces and non-final classes only; no partial mocks; no static method mocking. Anything more is post-v1.

## Validation

- Double every interface in Greenlight's own `src/` as a smoke test.
- Leak test: a `WeakReference` to every double created in a fixture run, all collected after each test.
