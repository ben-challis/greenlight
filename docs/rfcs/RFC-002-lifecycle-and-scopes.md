# RFC-002: lifecycle and harness scopes

| | |
|---|---|
| **Status** | accepted |
| **Phase** | 5a (docs/plan/phase-05a-worker-lifecycle.md) |
| **Author** | Ben Challis |
| **Date** | 2026-07-07 |

## Context

The worker runtime defines when test instances, hooks, and harness services are created and destroyed. The plugin API (Phase 7) exposes these semantics and the doubles library (Phase 9) hooks into per-test teardown, so they freeze here. Motivated by PRD sections 5, 7.1 and 9.

## Decision

### Scopes

Four scopes, each a `Greenlight\Harness\Scope` enum case: `PerTest`, `PerClass`, `PerSuite`, `PerRun`. `PerRun` means per worker lifetime, honestly documented as such. A scope is a container of services created on demand; closing a scope disposes its services in reverse creation order.

- Harness services are registered as `ServiceDefinition` (service type, scope, factory closure). Registration is internal in this phase; `HarnessProvider` plugins arrive in Phase 7.
- Resolution is by exact constructor parameter type only. No autowiring of arbitrary classes, no interface-to-implementation guessing. An unknown constructor parameter type errors the test with a message naming the type and the test class.
- Services are instantiated as PHP 8.4 lazy proxies (`ReflectionClass::newLazyProxy`) where the class supports it, so an injected but untouched `PerSuite` service costs nothing. Classes that cannot be lazy (readonly classes, internal classes) fall back to eager construction; this is a documented degradation, not an error.
- Disposal: services implementing `Disposable` get `dispose()` called at scope close, reverse creation order, exception-safe (every dispose runs even when an earlier one throws). Uninitialised lazy proxies are not initialised just to be disposed.

### Test lifecycle, in order

1. Skip checks run before anything is constructed: `#[Skip]` produces a skipped outcome immediately; `#[SkipUnless]` instantiates the condition (no-argument constructor) and skips when unsatisfied.
2. The per-test scope opens. The test class is constructed with constructor injection (exact type match against harness services; `Expect` is a built-in per-test service).
3. `#[Before]` hooks run in declaration order. A throwing hook skips the test method but not the after-hooks.
4. The test method runs, with data-set arguments when the plan entry carries a data-set key.
5. `#[After]` hooks run in reverse declaration order, always, including after failures. A throwing after-hook errors the test unless it already failed.
6. The per-test scope closes (doubles auto-verification hooks here in Phase 9); every reference to the test instance is dropped. A disposal that throws `ExpectationFailed` is a verification step and fails the test with its failure details; any other disposal throwable errors the test.

The per-class scope opens before a class's first test and closes after its last. A teardown failure at scope close is attributed to the test that triggered the close (the last test executed in that scope), turning a passed outcome into errored with the disposal throwable attached.

### Outcome mapping

- `ExpectationFailed` from the test method: failed, with its `FailureDetail` list.
- Any other throwable: errored, with `ThrowableDetail`.
- `#[Timeout]`: cooperative. The worker cannot interrupt a running test; it measures and fails the test after the fact when the duration exceeds the budget (message names the budget and actual). A hard kill from the orchestrator arrives with the process pool.
- `#[Retry(times: n, onlyOn: T)]`: a failed or errored attempt is retried with a fresh instance and a fresh per-test scope, up to n additional attempts, only when the failure cause matches T when given. `TestResult::$attempts` records the count; the final attempt's outcome stands.

### Data sets at execution time

Discovery expands data sets to keys at plan time; the worker re-invokes the provider (once per class, cached for the class scope's lifetime) and selects arguments by key. A key present in the plan but missing from the provider at execution time errors the test, naming the key: it means code changed between plan and execution.

### Events

The worker emits to an `EventSink` (interface, one `emit(Event $event)` method): `TestClassStarted`/`TestClassFinished` per class and `TestStarted`/`TestFinished` per test. Run-level events (`RunStarted`, `RunFinished`, suite and worker events) belong to the runner composing the worker, not to the worker itself.

### Runner and CLI in this phase

`InProcessRunner` composes discovery, one worker, and a summary in a single process; `bin/greenlight run` executes tests through it and exits 0 on success, 1 on failure or when no tests are found. A `--dry-run` flag prints the resolved configuration and plan without executing (this replaces the previous placeholder behaviour where `run` only printed the plan). Worker counts other than 1 fall back to sequential execution with a notice until the process pool exists. Rich reporters arrive later; the built-in output is a minimal dot stream with failure details and a summary line.

## Consequences

Frozen: scope semantics and disposal order, the lifecycle order above, outcome mapping, hook ordering, retry semantics, the `EventSink` seam, and the attribution rule for scope teardown failures. The plugin API will expose these as-is. Not frozen: the registration API for harness services (internal until Phase 7) and the process-pool composition (Phase 5b).

## Alternatives considered

- `pcntl_alarm`-based preemptive timeouts: rejected; signals interact unpredictably with user code and extensions, and the orchestrator-side kill covers the hard cases.
- Autowiring arbitrary constructor parameters: rejected; a test framework container that guesses is a debugging tax. Exact match keeps injection greppable.
- Attributing class-scope teardown failures to a synthetic class-level result: rejected; the result model deliberately has no class-level outcome, and reporters would need to special-case it.
- Invoking data-set providers once at discovery and shipping argument values to workers: rejected; arguments are arbitrary PHP values and the wire carries only rendered strings, never live values.
