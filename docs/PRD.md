# Greenlight: Product Requirements Document

| | |
|---|---|
| **Status** | Draft, pre-implementation |
| **Last updated** | 2026-07-06 |
| **Scope** | Product definition for Greenlight v1.0 and its roadmap |

---

## 1. Vision

Greenlight is a testing framework for PHP 8.4 and later: fully typed, leak-free by design, parallel by default, and extensible through a plugin API that gives extensions access to the live runtime.

PHP testing today forces a choice between two compromises. PHPUnit carries twenty years of accumulated API surface, an event system that deliberately withholds runtime context, and an execution model where parallelism (paratest) and memory hygiene are bolt-ons. Pest fixes ergonomics but does so with closure rebinding and file-level magic that static analysis and refactoring tools cannot see through, and it inherits PHPUnit underneath.

Greenlight is a new engine rather than a layer over an existing one. It combines an attribute-driven authoring model that PHPStan and IDEs understand natively, an orchestrator/worker runner where parallelism and worker recycling are the core execution path, a test-double library built on PHP 8.4 lazy objects with mandatory end-of-test teardown, and a plugin system whose interception points receive the actual test instance and its harness.

The pitch in one sentence: write typed tests, run them in parallel with flat memory, and extend the runner without fighting it.

## 2. Goals

1. Typed, analysable tests. Every construct a test author touches (tests, data sets, expectations, harness services, config) is expressible in plain typed PHP that PHPStan/Psalm at max level and IDE refactoring tools understand with zero framework-specific plugins.
2. Flat memory over arbitrary suite sizes. A 50,000-test run must not consume meaningfully more peak memory per worker than a 500-test run. This is a design constraint rather than an optimisation target.
3. Parallel by default. The out-of-box experience saturates available cores. Sequential execution is a configuration of the parallel runner (`workers: 1`) sharing the same code path.
4. Plugins with real context. Extensions can observe and participate in the run: read the test instance, inspect its metadata, access harness services, and influence outcomes through defined interception points.
5. A first-class operational surface. Output capture, multiple report formats, coverage export, and flow control (bail, retry, filtering, watch) are core capabilities with coherent APIs.
6. Ergonomics without magic. Low ceremony comes from attributes, constructor injection, and a fluent expectation API. Runtime generation of test cases, closure rebinding, and global mutable state are off the table.

## 3. Non-goals

- PHPUnit compatibility layer. Greenlight will not run PHPUnit test suites or emulate its assertion API. A separate migration tool (Rector rules) is roadmap Phase 4; emulation is never.
- PHP below 8.4. Lazy objects, property hooks, and asymmetric visibility are load-bearing in the design. Supporting older runtimes would force the same compromises we are escaping.
- Browser and end-to-end testing. Greenlight tests PHP code. Panther-style browser automation is plugin territory.
- Mutation testing in core. Infection-style mutation testing is a natural plugin (the plugin API is designed to make it possible).
- BDD/Gherkin syntax. No natural-language test definitions. Behat exists.
- Configuration in XML, YAML, JSON, or attributes on config classes. One configuration mechanism: fluent PHP.

## 4. Target users

1. Teams with large modern-PHP codebases (Symfony, Laravel, API platforms) whose CI is slow and whose long test runs exhibit memory creep. They want wall-clock speed and predictable resource usage without babysitting paratest and `@runInSeparateProcess`.
2. Static-analysis-first developers who run PHPStan at level max and find that their test code is the least-analysable code they own.
3. Library and framework authors who need precise lifecycle control, output capture, and machine-readable results for matrix CI builds.
4. Tooling authors (IDE integrations, CI dashboards, flaky-test detectors) who today scrape PHPUnit's output because its event API withholds the context they need.

Two groups are explicitly outside the initial target: teams on legacy PHP versions, and teams who want drop-in PHPUnit compatibility. We trade addressable market at launch for coherence; PHP 8.4 adoption grows into the design over time.

## 5. Test authoring model

### 5.1 Shape of a test

Tests are `final` classes. Test methods, lifecycle hooks, and data sets are declared with attributes. There are no method-name conventions (`testFoo`, `setUp`) and no closure DSL.

```php
use Greenlight\Attribute\{Test, DataSet, Before, After, Group, Retry, Skip};
use Greenlight\Expect\Expect;

final class InvoiceTotalsTest
{
    public function __construct(
        private readonly Expect $expect,
        private readonly ClockHarness $clock,   // harness service, per-test scope
    ) {}

    #[Before]
    public function freezeTime(): void
    {
        $this->clock->freeze('2026-07-06T00:00:00Z');
    }

    #[Test]
    #[DataSet('currencies')]
    public function totalsAreRoundedPerCurrency(Currency $currency, string $expected): void
    {
        $invoice = Invoice::draft($currency)->addLine('10.005');

        $this->expect->that($invoice->total())->toEqual($expected);
    }

    /** @return iterable<string, array{Currency, string}> */
    public static function currencies(): iterable
    {
        yield 'GBP rounds half-up' => [Currency::GBP, '10.01'];
        yield 'JPY has no minor unit' => [Currency::JPY, '10'];
    }
}
```

Decisions and rationale:

- Classes rather than closures. A class instance gives Greenlight (and every plugin) a real object to hand around: typed properties, reflection-friendly metadata, deterministic construction and destruction. It is also the only model where "the plugin receives the actual test instance" means anything.
- Attributes rather than naming conventions. `#[Test]` is explicit, greppable, and carries parameters (`#[Test(timeout: 5.0)]`). Magic method names are an implicit contract that tooling must special-case.
- Constructor injection rather than inheritance. There is no mandatory `TestCase` base class. Tests declare what they need (expectation service, harness services) in the constructor; the runner provides them. No `parent::setUp()` footguns, no 300-method inherited API, and test classes remain instantiable in isolation.
- Data sets are typed static methods referenced by name via `#[DataSet]`, returning `iterable` with named keys. Enums implementing a `DataProvider` interface are also accepted. String-encoded provider references to other classes are not supported.

### 5.2 Expectations

Assertions are a fluent, terminal expectation API delivered as an injected service (`Expect`). There is no base-class method bag; an `expect()` function wrapper is available for those who want it, but it resolves to the same service with no hidden state.

```php
$this->expect->that($response)
    ->toBeInstanceOf(JsonResponse::class)
    ->and($response->getStatusCode())->toBe(201);

$this->expect->that($fn)->toThrow(DomainException::class, matching: '/insufficient funds/');
```

- Failures are collected with rich diffs (typed value renderers, no `var_export` dumps).
- Soft expectations are explicit: `$this->expect->softly(function (Expect $e) { ... })` gathers multiple failures before failing the test. The default remains fail-fast per expectation, because implicit soft-assertion modes hide broken tests.
- The vocabulary is extensible via expectation plugins (see section 8), and extensions are typed: a plugin ships an interface extension plus implementation, so `->toBeValidUuid()` autocompletes and analyses like core matchers.

### 5.3 Flow-control attributes

`#[Skip(reason)]`, `#[SkipUnless(Condition::class)]`, `#[Group('slow')]`, `#[Retry(times: 2, onlyOn: NetworkException::class)]`, `#[Timeout(seconds: 5)]`, `#[Isolated]` (run in a dedicated fresh worker).

There is deliberately no `#[Depends]`. Inter-test dependencies create hidden ordering contracts that break under parallelism. The supported pattern for expensive shared state is a suite- or class-scoped harness service (section 9), which is explicit, typed, and parallel-safe.

## 6. Configuration

One mechanism: a `greenlight.php` file at the project root returning a fluent, fully typed builder, following the PHPStan/Rector configuration model. It was chosen because it is IDE-discoverable, refactorable, and can compute (env-dependent worker counts, conditional plugins) without a templating layer.

```php
use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths(tests: ['tests'])
    ->suite('unit', fn ($s) => $s->in('tests/Unit'))
    ->suite('integration', fn ($s) => $s->in('tests/Integration')->tag('io'))
    ->workers(count: 'auto', recycleAfterTests: 500, recycleAboveMemory: '256M')
    ->coverage(fn ($c) => $c->include('src')->driver('pcov')->export('lcov', 'coverage/lcov.info'))
    ->plugins(
        new SymfonyKernelPlugin(env: 'test'),
    )
    ->failFast(false)
    ->randomizeOrder(seed: null);
```

- There will never be XML, YAML, or JSON config (non-goal, section 3). One format means one documentation surface and no schema drift.
- CLI flags override config (`greenlight --workers=1 --group=slow --bail`); config overrides defaults. Precedence is documented and never conditional.
- The config object is immutable once loaded and available to plugins as a typed value object.

## 7. Execution model

### 7.1 Architecture

An orchestrator process and a pool of worker processes, communicating over a binary-framed protocol on local sockets:

1. Discovery. The orchestrator statically discovers test classes (composer classmap plus attribute scan; no test code is executed during discovery) and builds an execution plan.
2. Distribution. Tests are distributed to workers deterministically (stable hash of class name) so a given seed and code state always yield the same placement. An optional timing cache (`.greenlight/timings`) rebalances by recorded duration to minimise tail latency.
3. Execution. Each worker runs tests sequentially within itself: it constructs the test class, resolves harness scopes, runs hooks and the test, tears down, and streams structured results (events, captured output, timings, memory delta) back to the orchestrator as they happen.
4. Recycling. Workers self-terminate and are replaced after `recycleAfterTests` tests or when exceeding `recycleAboveMemory`. This is the backstop that makes goal 2 (flat memory) hold even when the code under test leaks.

Consequences we accept: crash containment (a segfault kills one worker, the orchestrator records the offending test and continues) and honest global state semantics (anything static is per-worker; tests that require true isolation declare `#[Isolated]`).

Two alternatives were rejected. Sequential-first with parallelism added later is how PHPUnit ended up with paratest's limitations. Fiber-based single-process concurrency offers no crash isolation and no memory isolation, and PHP's ecosystem of blocking IO makes it a poor default; a Fiber-pool worker type may come later for IO-bound suites (see roadmap).

### 7.2 Flow control

- `--bail[=N]` stops the run after N failures (the orchestrator drains workers gracefully).
- `--group` / `--exclude-group`, path, class, and method filters; `--rerun-failed` replays the last run's failures first.
- Watch mode (`greenlight --watch`) re-runs affected tests on file change, using the coverage-derived or path-heuristic mapping.
- Ordering: the default is defined order per class, randomised class order with printed seed. Randomisation surfaces hidden coupling early; per-method randomisation is opt-in.

### 7.3 Output capture

Each worker captures `stdout`, `stderr`, and PHP notices/warnings/deprecations per test via stream interception installed around test execution. Captured output is attached to the structured result, shown on failure, available to reporters, and queryable by plugins. Escaped output (echo from the code under test) never corrupts the renderer or machine-readable streams, a chronic PHPUnit annoyance.

## 8. Plugin architecture

This is where Greenlight departs furthest from PHPUnit. PHPUnit's event system deliberately emits immutable, context-stripped value objects: a subscriber cannot see the test instance, cannot touch the container, cannot alter outcomes. That protects PHPUnit's internals at the cost of making whole categories of tooling impossible. Greenlight takes the opposite bet: a smaller, versioned set of interception points that receive live context.

- Typed subscriber interfaces, no string event names. A plugin implements e.g. `TestLifecycleSubscriber` with `beforeTest(TestContext $ctx)` / `afterTest(TestContext $ctx, TestResult $result)`. Registration is discovered from the interfaces the plugin class implements.
- `TestContext` carries the live runtime: the actual test instance, its `TestMetadata` (class, method, attributes, data-set key), the harness scope container, the run configuration, and an output sink. Plugins in `afterTest` receive the result before it is final and may annotate it or, via an explicit and logged API, transform it. This is how flaky-quarantine and retry policies get built outside core.
- Plugin types are capability-scoped, each a separate interface: `Reporter` (consume the structured result stream), `ExpectationExtension` (add matchers), `HarnessProvider` (contribute injectable scoped services; this is how Symfony/Laravel bridges work), `RunLifecycleSubscriber` (orchestrator-side: run start/end, worker spawn/recycle), `TestLifecycleSubscriber` (worker-side). One plugin class may implement several.
- Every interception point is documented as orchestrator-side or worker-side, because they are different processes. The framework serialises what crosses the boundary; live objects (test instance, harness) are only available worker-side. This constraint is stated in the API rather than discovered in production.
- The plugin surface has its own semver contract, narrower than the framework's internals, so internals can evolve without breaking the ecosystem. PHPUnit locked its internals down; Pest plugins reach into unversioned guts. Both approaches have caused years of ecosystem pain, and a versioned narrow surface is the lesson from each.

The trade-off: giving plugins live context means a misbehaving plugin can corrupt a test run. We accept this. The mitigations are worker isolation (blast radius is one worker), the logged outcome-transformation API (mutations are attributable in the report), and leak-checking plugins in CI (section 12), rather than a crippled API.

## 9. Harness and fixtures

The harness is Greenlight's replacement for `setUp()` sprawl, static fixture caches, and base-class inheritance chains: a small scoped-service layer purpose-built for tests.

- Harness services are plain classes registered by `HarnessProvider` plugins or in config, each with a declared scope: `perTest`, `perClass`, `perSuite`, or `perRun` (per-run means per-worker-lifetime, and the docs say so honestly).
- Tests receive them by constructor injection, resolved by type. There is no service-locator API on the test.
- Scopes have deterministic teardown: when a scope closes, its services are disposed in reverse creation order via a `Disposable` interface. Databases get truncated, temp dirs deleted, containers stopped, in a defined order, always, including on test failure.
- Expensive shared fixtures (a booted Symfony kernel, a migrated database, a Testcontainer) are `perClass`/`perSuite` harness services. This is the sanctioned replacement for `#[Depends]` and `setUpBeforeClass()` statics.
- PHP 8.4 lazy objects make scoped services cheap to declare: a `perSuite` database harness costs nothing in suites that never touch it.

## 10. Output and reporting

A `Reporter` consumes the orchestrator's structured result stream; multiple reporters run concurrently. Built-ins at v1:

| Reporter | Purpose |
|---|---|
| `tty` (default) | Rich interactive output: live progress by worker, failure diffs, captured output, slowest-tests summary, memory summary |
| `plain` | Deterministic non-ANSI output for CI logs |
| `jsonl` | One JSON object per event, streamed; the machine interface for IDEs, dashboards, and flaky-test tooling |
| `junit` | JUnit XML for CI systems |
| `github` | GitHub Actions annotations (failures annotated on the PR diff) |
| `teamcity` | TeamCity service messages (drives PhpStorm's test UI) |

Every reporter renders from the same structured result model (status, timings, memory delta, captured output, expectation diffs, retry history), so every format has access to the full picture. Custom reporters are around 50 lines of plugin.

## 11. Coverage

- pcov is the recommended driver and the docs say so plainly (it is dramatically faster); Xdebug is fully supported for branch/path coverage, which pcov cannot do.
- Collection is per-worker, merged incrementally by the orchestrator. There is no giant end-of-run merge spike, keeping goal 2 intact under coverage.
- Export formats at v1: lcov, Clover, Cobertura, HTML, and Greenlight's own JSON (a documented schema including per-test coverage mapping when enabled).
- Baseline export and diff: `greenlight coverage:diff --against=baseline.json` reports coverage change for the patch, designed for "coverage must not decrease" CI gates without third-party services.
- Per-test coverage mapping (which tests execute which lines) is an opt-in flag; it powers watch mode's affected-test selection and external mutation-testing plugins.

## 12. Memory and lifecycle principles

These are engineering commandments for Greenlight's own codebase, testable and enforced in its CI:

1. No static registries. Framework state lives in objects owned by the runner with explicit lifetimes. `static` is permitted only for immutable memoisation of pure computation.
2. Every scope has a destruction contract. Anything created for a test/class/suite scope is disposed when the scope closes, in reverse order, exception-safe.
3. Test instances die with their test. After `afterTest` interception completes, the framework drops every reference to the instance, its doubles, and its per-test harness; a debug mode (`--detect-leaks`) uses `WeakReference` to verify collection and names the test that leaked.
4. Caches hold weak references (`WeakMap`) wherever the cache is an optimisation rather than an owner.
5. Worker recycling is a backstop for leaks in user code, never an excuse for leaks in the framework. The framework itself must pass a CI job that runs 10,000 synthetic tests in one worker and asserts flat memory (under 1 MB drift).
6. Streaming everywhere. Results, coverage, and reporter output stream; the orchestrator never accumulates unbounded per-test data in memory (reporters that need aggregates keep bounded summaries).

## 13. Architecture principles

- A component monorepo, Symfony-style. One repository, separable read-only-split packages: `greenlight/runner`, `greenlight/expect`, `greenlight/doubles`, `greenlight/harness`, `greenlight/reporting`, `greenlight/coverage`, with `greenlight/greenlight` as the metapackage users install. Boundaries are enforced (deptrac in CI); `expect` and `doubles` must be usable standalone.
- `final` by default, interfaces at boundaries. Extension happens through the plugin API, never through inheritance of internals.
- Fully typed, PHPStan level max, no baseline, from day one.
- No service location and no globals in framework code; the container that resolves harness services is internal and never exposed as a locator.
- The framework tests itself with itself from the earliest possible phase (bootstrapped initially by a thin assertion script until `expect` is self-hosting).

## 14. Mocking and test doubles (native)

Greenlight ships `greenlight/doubles`, its own test-double library, because lifecycle-safe doubles cannot be retrofitted onto libraries with their own static state (Mockery's global container being the canonical example).

```php
#[Test]
public function chargesTheCard(): void
{
    $gateway = $this->doubles->mock(PaymentGateway::class, function (MockPlan $plan) {
        $plan->expects('charge')->with(Money::gbp('10.00'))->once()->andReturns(ChargeResult::ok());
    });

    (new Checkout($gateway))->complete($this->cart());
    // verification is automatic at test end: no manual verify() call, no leaked expectations
}
```

- Built on PHP 8.4 lazy objects plus generated proxies. Doubling a class does not run its constructor; interfaces get generated implementations. Proxy classes are cached per-worker and invalidated by signature hash.
- Strict by default. An unexpected call on a mock fails the test immediately with the received arguments diffed against declared expectations. Loose behaviour is explicit: `stub()` (returns configured or null-safe defaults, records nothing enforced), `spy()` (records everything, assert afterwards), `fake()` (hand-written in-memory implementations, supported by a `Fake` marker interface so reporters can label them).
- Verification is integrated with the expectation engine. Mock failures render with the same diff quality as `expect` failures, and spy assertions read as expectations: `$this->expect->that($spy)->toHaveReceived('charge')->once()`.
- Doubles are per-test scoped services. They are created through the injected `Doubles` factory, owned by the per-test scope, auto-verified and auto-disposed at test end (principle 12.3). A double cannot outlive its test.
- Mockery and Prophecy remain usable through lifecycle-adapter plugins (community-maintained; the plugin API's `afterTest` hook is sufficient to integrate their verify/close cycles).

Building a doubles library is the largest single scope item in this PRD. We take it because goals 1, 2, and 6 are unachievable with third-party doubles, and because doubles are where test DX is won or lost. Scope containment: v1 doubles cover interfaces and non-final classes; doubling `final` classes (via bytecode-level tricks) is explicitly out of scope. The documented pattern is to double an interface, which is better design anyway.

## 15. Roadmap

Phase 0, design (now). This PRD; architecture RFCs for the worker protocol, plugin API surface, and doubles proxy generation. Exit: RFCs reviewed, repo scaffolding (CI, PHPStan, deptrac) in place.

Phase 1, runnable core. Config loader, discovery, orchestrator/worker runner with recycling, `expect` with core matchers, `#[Test]`/`#[Before]`/`#[After]`/`#[DataSet]`/`#[Group]`/`#[Skip]`, output capture, `tty` + `plain` + `junit` reporters, `--bail`/filters. Exit: Greenlight runs its own test suite in parallel with flat memory in CI.

Phase 2, doubles and harness. `greenlight/doubles` (mock/stub/spy/fake, auto-verify), harness scopes with deterministic teardown, `#[Retry]`/`#[Timeout]`/`#[Isolated]`, `jsonl` reporter, leak-detection mode. Exit: a real mid-size open-source project ports its unit suite as a case study.

Phase 3, coverage and plugin GA. pcov/Xdebug coverage with per-worker merge and all export formats, coverage baseline diff, plugin API declared stable (semver), watch mode, `github` + `teamcity` reporters. Exit: v1.0 release.

Phase 4, ecosystem. Symfony and Laravel harness bridges, Rector-based PHPUnit migration rules, per-test coverage mapping, timing-based rebalancing, exploratory Fiber-pool worker for IO-bound suites.

Sequencing rationale: the runner comes first because every other component's API is shaped by the process boundary; doubles come before coverage because doubles block real-world dogfooding; the plugin API stabilises last so two phases of internal use harden it before the semver promise.

## 16. Success metrics

- Performance: at least 2x wall-clock improvement over PHPUnit plus paratest on a public reference suite at equal worker counts; worker peak memory flat (under 1 MB drift) across a 10,000-test synthetic run.
- Analysability: a Greenlight test suite passes PHPStan level max with zero framework-specific extensions installed.
- Extensibility proof: flaky-test quarantine and a mutation-testing prototype are implementable as external plugins without patching core. This is the acceptance test for section 8's design.
- Adoption proxies (12 months post-v1): three or more non-trivial open-source projects using it in CI; community plugins exist for at least one DI framework bridge not written by us.

## 17. Risks

| Risk | Assessment | Mitigation |
|---|---|---|
| PHP 8.4 floor limits early adoption | Real, accepted deliberately | Target greenfield projects and libraries first; the adoption curve of 8.4 does the rest. Do not soften the floor. |
| Native doubles scope balloons | Highest-effort component | Contained scope (no final-class doubling in v1); ship in Phase 2 rather than 1; Mockery adapter as pressure valve. |
| Live-context plugin API enables misbehaving plugins | Accepted trade-off (section 8) | Worker blast-radius containment, logged outcome mutations, API semver reviewed before GA. |
| Worker protocol complexity (serialisation across the process boundary) | Main technical risk in Phase 1 | Dedicated RFC before implementation; keep the wire model minimal (results, never objects); property-based tests on the protocol. |
| "Yet another framework" fatigue | Positioning risk | Lead with the two provable differentiators, flat memory at scale and the plugin API, backed by published benchmarks. |
