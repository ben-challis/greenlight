# RFC-001: result and event model

| | |
|---|---|
| **Status** | accepted |
| **Phase** | 1 (docs/plan/phase-01-core-model.md) |
| **Author** | Ben Challis |
| **Date** | 2026-07-07 |

## Context

Every component consumes the core value objects: the wire protocol (Phase 5b) transports them, reporters (Phase 8) render them, and the plugin API (Phase 7) exposes a subset of them. This RFC freezes their shape. Motivated by PRD sections 5, 7.1 and 8.

## Decision

### Identity and metadata

- `Greenlight\Core\Test\TestId`: readonly value of `class`, `method`, and optional `dataSetKey` (string). Stable across processes and runs given the same code state. Renders as `Class::method[key]`. Data-set keys are plain strings; keys that are not printable are hashed at discovery time (Phase 3), never inside `TestId`.
- `Greenlight\Core\Test\TestMetadata`: readonly description of a discovered test method: class, method, groups, skip/skip-unless, retry policy, timeout, isolation flag, data-set provider name.

### Attributes

Attributes live in `Greenlight\Attribute\` (directory `src/Attribute/`, part of the `Core` deptrac layer). Method-only: `Test`, `Before`, `After`, `DataSet`. Method-or-class: `Group` (repeatable), `Skip`, `SkipUnless`, `Retry`, `Timeout`, `Isolated`. Constructor arguments are validated at construction (`Retry` times >= 1, `Timeout` seconds > 0) so invalid metadata fails at discovery, not mid-run.

`SkipUnless` references a `class-string` of `Greenlight\Core\Condition`, a one-method interface (`isSatisfied(): bool`) evaluated at execution time.

### Results

- `Outcome` is a string-backed enum: `passed`, `failed`, `errored`, `skipped`. **Deviation from the PRD wording:** `retried` is not a terminal outcome. A retried test ends in one of the four terminal outcomes; the retry history is carried by `TestResult::$attempts`. A fifth enum case would force every consumer to answer "retried and then what?".
- `FailureDetail`: message plus optional pre-rendered `expected`/`actual` strings and a `SourceLocation`. Rendering happens in `Expect` (worker side); the wire carries rendered strings, never live values.
- `ThrowableDetail`: class, message, file, line, and a bounded list of rendered stack frames. Built via `ThrowableDetail::fromThrowable()`.
- `TestResult`: readonly aggregate of `TestId`, `Outcome`, duration, memory delta, attempts, failures, optional error, optional skip reason, and the outcome-transformation log. It is immutable; plugins produce a replacement via `withOutcome(Outcome, string $transformedBy)`, which appends an `OutcomeTransformation` (by, from, to) so mutations are attributable in reports (PRD section 8).
- Captured output is not on `TestResult` yet; Phase 6 adds it additively.

### Events

A closed set of readonly classes in `Greenlight\Core\Event\`, all implementing `Event`, which declares `public float $occurredAt { get; }` (a PHP 8.4 interface property) and extends `WireSerializable`: `RunStarted`, `RunFinished` (carrying `ResultSummary` counts), `SuiteStarted`, `SuiteFinished`, `TestClassStarted`, `TestClassFinished`, `TestStarted`, `TestFinished` (carrying the full `TestResult`), `WorkerSpawned`, `WorkerRecycled` (with a `RecycleReason` enum: test count, memory, crash).

Granularity: per-expectation events are rejected as too chatty for the wire. The event set may grow additively; existing event shapes are frozen.

### Wire contract

`WireSerializable` declares `toWire(): array<string, mixed>` and `fromWire(array $payload): static`. Payloads must survive a JSON round trip (floats may come back as ints; readers coerce). Reading is done through the `Greenlight\Core\Wire\Wire` helper, which throws `InvalidWirePayload` naming the offending key; optional fields use nullable readers so a missing key is a protocol error, distinct from a present null. A type-discriminator registry for envelope dispatch is deferred to Phase 5b (RFC-003); class names are sufficient until a cross-process envelope exists.

Strings originating in user code (exception messages, file paths, rendered values) can contain bytes that are not valid UTF-8, which JSON cannot encode. Such strings are scrubbed at capture via `Greenlight\Core\Wire\Utf8::scrub()` (invalid sequences become U+FFFD); `ThrowableDetail::fromThrowable()` does this today, and Phase 4's renderers must do the same before constructing `FailureDetail`. The RFC-003 codec additionally encodes with `JSON_INVALID_UTF8_SUBSTITUTE` as defence in depth.

## Consequences

Frozen: the shapes above, the enum backings, and the wire payload keys. Additive change (new optional fields with defaults, new events) is allowed; anything else needs a superseding RFC. Reporters, the protocol, and the plugin surface can now be built against stable types.

## Alternatives considered

- `retried` as a terminal `Outcome` case (the PRD's literal wording): rejected because it destroys the invariant that an outcome answers "did it pass"; every consumer would special-case it.
- Mutable `TestResult` for plugin transformation: rejected; immutability plus a provenance log keeps section 8's auditability promise without defensive copying.
- PHP `serialize()` for the wire: rejected; opaque, version-fragile, and an injection surface. Explicit arrays are greppable and diffable.
- Events as string names plus payload arrays (PHPUnit-style): rejected; typed classes are the whole point of the plugin API.
