# RFC-004: plugin API surface

| | |
|---|---|
| **Status** | accepted, surface experimental until GA |
| **Phase** | 7 (docs/plan/phase-07-plugins.md); GA review in Phase 13 |
| **Author** | Ben Challis |
| **Date** | 2026-07-07 |

## Context

The extension contract is the product's headline differentiator: plugins receive live runtime context through typed interfaces rather than context-stripped value objects. This RFC defines the capability-scoped plugin types, the context object, ordering, error policy, and how plugins reach worker processes. The whole surface carries an experimental marker until the Phase 13 GA review; between now and then it may change with a changelog entry but not silently.

## Decision

### Registration and transport

Plugins are objects passed to `GreenlightConfig::plugins()`. Capabilities are discovered from the interfaces a plugin implements; one object may implement several. Live objects cannot cross the process boundary, so each worker loads the config file itself and instantiates the plugins worker-side: `assign` carries the config file path, and a worker without one runs plugin-free. Plugin construction is therefore repeated per worker, which is the honest cost of live context; plugins requiring cross-worker state must use external storage.

### Capability interfaces

All in `Greenlight\Plugin` unless noted.

- `TestLifecycleSubscriber` (worker-side): `beforeTest(TestContext $context): void` and `afterTest(TestContext $context, TestResult $result): TestResult`. `afterTest` returns the result, replaced or untouched. Outcome changes are only legal through `TestResult::withOutcome()`: the executor verifies that a changed outcome grew the transformation log, and a plugin that mutates outcome without provenance errors the test with the plugin named.
- `RetryDecider` (worker-side): `shouldRetry(TestMetadata $metadata, TestResult $result, int $attempt, ?\Throwable $cause): bool`. Consulted after each failed attempt; any decider saying yes triggers another attempt within the executor's loop. It receives metadata and the live cause rather than a `TestContext` because the failed attempt's instance and scope are already gone when the decision is made; handing out a context here would keep dead test instances alive.
- `RunLifecycleSubscriber` (orchestrator-side): `onRunEvent(Event $event): void`, receiving run, worker, suite, class, and test events as they reach the orchestrator. Read-only observation; results cannot be altered from this side of the boundary.
- `HarnessProvider`: `services(): list<ServiceDefinition>` (from `Greenlight\Harness`), merged into the worker registry after the built-ins; duplicate types are a configuration error.
- `ExpectationExtension` (in `Greenlight\Expect`, existing): named matcher predicates dispatched by `Expectation::__call`, so `->toBeValidUuid()` works when an extension provides `toBeValidUuid`. Extension matchers cannot shadow native matchers.
- `Reporter` (in `Greenlight\Reporting`, existing): consumes the event stream; selected via CLI today, plugin-provided reporters join at GA.

### TestContext

Worker-side, per attempt: the live test `instance`, the `TestId`, the `TestMetadata`, and `service(class-string $type): object` resolving from the active harness scopes (the same resolution constructor injection uses). It deliberately does not expose scope open/close, the sink, or the plan: observation and service access, not lifecycle control.

### Ordering and error policy

Subscribers run in registration order; a plugin additionally implementing `Prioritized` (`priority(): int`, lower runs earlier, default 0) is stably sorted first. A throwing `beforeTest` errors the test with the plugin class named and skips the method but not after-hooks or remaining `afterTest` subscribers; a throwing `afterTest` errors the test unless it already failed. Plugin failures are never swallowed and never abort the run.

### Internal proof

`#[Retry]` policy is implemented as an internal `RetryDecider` plugin registered before user plugins; the API must be strong enough to carry it or it is not strong enough to publish. Attribute-driven skips stay a pre-construction executor concern (RFC-002 froze that skips run before anything is constructed, and `beforeTest` requires an instance); the public `SkipTest` control signal covers the runtime half: user code, before-hooks, and `beforeTest` subscribers may throw it and the test reports skipped with the given reason.

## Consequences

Frozen at GA, experimental until then: the six capability interfaces, `TestContext`'s accessor set, the provenance guard, and the ordering/error rules. The result and event model types these interfaces expose get promoted out of `@internal` at the Phase 13 review as the plugin-visible subset.

## Alternatives considered

- Serialising plugin state to workers instead of re-instantiating from config: rejected; arbitrary object serialisation is the exact wire hazard RFC-001 banned.
- String event names with array payloads: rejected throughout; typed interfaces are the point.
- Allowing `afterTest` to freely construct replacement results: rejected; unattributed outcome changes are how frameworks lose trust in their own reports.
- A generic interceptor (`intercept(context, next)`) instead of `RetryDecider`: more powerful, but middleware around test execution invites lifecycle violations the scopes cannot police; revisit post-GA if real plugins need it.
