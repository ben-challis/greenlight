# Greenlight Implementation Plan

> **For agentic workers:** use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement Phase 0 task-by-task. Later phases each get their own detailed plan document before execution; this document fixes their scope, order, and interfaces.

**Goal:** bootstrap the Greenlight repository with production-grade tooling, then build the framework defined in [PRD.md](PRD.md) through phases that each end in something runnable.

**Architecture:** a single monorepo with deptrac-enforced component boundaries (`Core`, `Config`, `Discovery`, `Expect`, `Runner`, `Harness`, `Doubles`, `Capture`, `Plugin`, `Reporting`, `Coverage`). The serial spine is Core domain model, then worker lifecycle, then plugin context; everything else hangs off frozen interfaces and can proceed in parallel.

**Tech stack:** PHP 8.4+, zero runtime Composer dependencies, dev tooling via PHPStan (max, strict rules, no baseline), PHP-CS-Fixer (PER-CS 2.0 base), Rector, deptrac, parallel-lint, composer-normalize, GitHub Actions.

---

## Part 1: Phase 0, repository bootstrap

### Decisions locked in this phase

1. **Zero runtime dependencies.** `composer.json` `require` contains only `php: >=8.4`. A testing framework is installed into every project's dev dependencies; any runtime dependency it carries becomes a version conflict with the code under test. This forces us to own small amounts of code (CLI argument parsing, a process abstraction) and that is the right trade. `ext-pcntl` and `ext-sockets` are `suggest`ed, not required; the runner detects them and degrades to `workers: 1` without them.
2. **Package name `greenlight/greenlight`, namespace `Greenlight\`.** Component sub-namespaces (`Greenlight\Expect\`, `Greenlight\Runner\`, ...) map to `src/<Component>/`. Read-only package splits are deferred until after v1.0; deptrac enforces the boundaries from day one so the split stays possible.
3. **Composer scripts are the task runner.** No Makefile, no justfile. `composer static-analysis`, `composer tests`, `composer code-style:fix` and friends are the entire developer interface, and CI calls exactly the same scripts so local and CI behaviour cannot drift.
4. **No PHPUnit, ever, including for bootstrapping.** The PRD commits to self-hosting. Until the engine can run tests (end of Phase 5), the suite runs under `tools/bootstrap-runner.php`: a deliberately dumb single-file runner (target under 200 lines) that instantiates `*Test` classes, calls `#[Test]` methods, and reports via exit code. It has no assertions of its own; tests use `Greenlight\Expect` as soon as it exists (Phase 4), and native `assert()` before that. The bootstrap runner is deleted in Phase 12 and nothing may depend on its behaviour.
5. **Coding standard:** PER-CS 2.0 via PHP-CS-Fixer with a small strict overlay (`declare(strict_types=1)` enforced, ordered imports, no yoda). PHPStan at level max with `strict-rules` and `bleedingEdge`, no baseline file permitted in the repo.
6. **Branching and commits:** trunk-based on `main`, conventional commits, squash merges. CHANGELOG generation and release automation are deferred to Phase 13.
7. **Licence: MIT.** The norm for PHP dev tooling; anything copyleft would gate adoption.

### File tree created in this phase

```
greenlight/
├── .editorconfig
├── .gitattributes              # export-ignore for tests/, docs/, tooling configs
├── .github/
│   ├── workflows/ci.yml
│   ├── PULL_REQUEST_TEMPLATE.md
│   └── ISSUE_TEMPLATE/ (bug.yml, feature.yml)
├── .gitignore
├── .php-cs-fixer.dist.php
├── CONTRIBUTING.md
├── LICENSE
├── README.md                   # already exists; expanded with badges + quick start placeholder
├── SECURITY.md
├── bin/greenlight              # CLI entry point stub
├── composer.json
├── deptrac.yaml
├── docs/
│   ├── PRD.md                  # already exists
│   ├── IMPLEMENTATION_PLAN.md  # this file
│   ├── rfcs/                   # architecture RFCs land here (template included)
│   └── architecture/           # living component docs, one per component as it lands
├── phpstan.dist.neon
├── rector.php
├── src/Core/.gitkeep           # components appear as their phases land
├── tests/
│   ├── Unit/
│   ├── Acceptance/             # end-to-end: run fixture suites, assert on output
│   └── Fixture/                # small self-contained test suites used as inputs
└── tools/bootstrap-runner.php
```

### Key file contents

`composer.json`:

```json
{
    "name": "greenlight/greenlight",
    "description": "An opinionated testing framework for PHP 8.4+: typed attribute-driven tests, parallel-first execution with flat memory, lifecycle-safe test doubles.",
    "license": "MIT",
    "type": "library",
    "keywords": ["testing", "test-framework", "parallel", "mocking", "coverage"],
    "require": {
        "php": ">=8.4"
    },
    "require-dev": {
        "deptrac/deptrac": "^3.0",
        "ergebnis/composer-normalize": "^2.47",
        "php-cs-fixer/shim": "^3.75",
        "php-parallel-lint/php-parallel-lint": "^1.4",
        "phpstan/phpstan": "^2.1",
        "phpstan/phpstan-deprecation-rules": "^2.0",
        "phpstan/phpstan-strict-rules": "^2.0",
        "rector/rector": "^2.0"
    },
    "suggest": {
        "ext-pcntl": "Process control for the parallel runner (POSIX)",
        "ext-sockets": "Orchestrator/worker communication",
        "ext-pcov": "Fast line coverage collection"
    },
    "autoload": {
        "psr-4": { "Greenlight\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Greenlight\\Tests\\": "tests/" }
    },
    "bin": ["bin/greenlight"],
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "ergebnis/composer-normalize": true
        }
    },
    "scripts": {
        "code-style:check": "php-cs-fixer fix --dry-run --diff --ansi",
        "code-style:fix": "php-cs-fixer fix --diff --ansi",
        "deptrac": "deptrac analyse --no-progress",
        "lint": ["parallel-lint src tests tools bin"],
        "phpstan": "phpstan analyse --ansi --no-progress --memory-limit=-1",
        "rector:check": "rector --dry-run --ansi --no-progress-bar",
        "rector:fix": "rector --ansi --no-progress-bar",
        "static-analysis": [
            "@composer validate --strict",
            "@composer normalize --dry-run",
            "@lint",
            "@code-style:check",
            "@phpstan",
            "@rector:check",
            "@deptrac"
        ],
        "tests": "php tools/bootstrap-runner.php tests/Unit tests/Acceptance"
    }
}
```

The `tests` script is redefined in Phase 12 to `bin/greenlight`. Nothing else changes at cutover, which is the point.

`.github/workflows/ci.yml` (shape, not final YAML):

- Triggers: `pull_request`, `merge_group`, `push` to `main`. Concurrency group keyed on head ref with cancel-in-progress.
- Job `ci`, matrix over PHP `8.4` and `8.5` and dependency sets `lowest`/`highest`/`locked` (via `ramsey/composer-install`), `fail-fast: false`.
- Steps: checkout, `shivammathur/setup-php` (with `pcov`, `pcntl`, `sockets`), install deps, then `composer static-analysis` (locked deps only, one PHP version) and `composer tests` (all cells).
- A second job `memory` is added in Phase 11 (the 10,000-test flat-memory gate); a placeholder comment marks where.

`deptrac.yaml` layers (the architecture, encoded): `Core` depends on nothing. `Config`, `Discovery`, `Expect`, `Capture`, `Harness`, `Doubles`, `Coverage` depend only on `Core`. `Plugin` depends on `Core` and `Harness`. `Reporting` depends on `Core`. `Runner` (orchestrator + worker) is the only layer allowed to depend on everything, because it composes the system. `Cli` depends on `Runner` and `Config`. Violations fail CI; there is no baseline.

`bin/greenlight` stub: shebang + strict types, checks PHP version >= 8.4 with a friendly error, locates the autoloader (project-local vs dependency install), prints name/version/help for `--version`/`--help`, exits 64 for unknown commands. Real command dispatch replaces the stub in Phase 2.

`CONTRIBUTING.md` covers: PHP 8.4 requirement, `composer static-analysis && composer tests` before pushing, conventional commit format, the no-baseline PHPStan rule, the zero-runtime-dependency rule, and where RFCs live. `SECURITY.md` gives a private disclosure route.

`.gitignore`: `/vendor/`, `/composer.lock` excluded? No: the lock file is committed (this is an application-like repo in development even though it ships as a library; `lowest`/`highest` CI cells cover the library consumer story). Ignore `/vendor/`, `.php-cs-fixer.cache`, `/.greenlight/` (runtime caches: timings, proxies), `.idea/`.

### Phase 0 validation

- Fresh clone on PHP 8.4: `composer install && composer static-analysis && composer tests` all green (tests trivially green: one placeholder test class proving the bootstrap runner executes and fails on a failing test).
- `bin/greenlight --version` prints a version string; unknown flags exit non-zero.
- CI matrix green on 8.4 and 8.5, all three dependency sets.
- `composer validate --strict` and `composer normalize --dry-run` pass.

---

## Part 2: phased roadmap

Phases are numbered in recommended execution order. "Interface freeze" means the phase ends with an RFC-reviewed set of interfaces committed to `docs/rfcs/`; later phases may add to frozen interfaces but not change them without a new RFC.

### Phase 1: core domain model

- **Goals:** define the value objects the entire system shares, before anything consumes them. This is the highest-leverage phase in the plan; every later component and the wire protocol are shaped by these types.
- **Key tasks:** attribute classes (`#[Test]`, `#[Before]`, `#[After]`, `#[DataSet]`, `#[Group]`, `#[Skip]`, `#[SkipUnless]`, `#[Retry]`, `#[Timeout]`, `#[Isolated]`); `TestId` (class + method + data-set key, stable and serialisable); `TestMetadata`; the result model (`TestResult`, `Outcome` enum: passed/failed/errored/skipped/retried, `FailureDetail` with typed diff payloads); the event model (a closed set of run events as `readonly` classes: run/suite/class/test started and finished, worker spawned/recycled); serialisation contracts for everything that will cross the process boundary (explicit `toWire()`/`fromWire()` arrays, no PHP `serialize()`).
- **Deliverables:** `src/Core/` complete with unit tests under the bootstrap runner; RFC-001 (result and event model) merged.
- **Design decisions:** granularity of events (per-expectation events are rejected: too chatty for the wire); whether `TestResult` is mutable during `afterTest` interception (decision: immutable, plugins produce a replacement via a `withOutcome()` API that records provenance, per PRD section 8); how data-set keys appear in `TestId` (string keys, hashed if not printable).
- **Dependencies:** Phase 0.
- **Risks:** over-modelling before real consumers exist. Mitigation: model only what the PRD names, mark everything `@internal` until Phase 7 freezes the plugin-visible subset.
- **Validation:** wire round-trip property tests (encode/decode equality) for every serialisable type; PHPStan max clean; deptrac shows `Core` depends on nothing.

### Phase 2: fluent configuration system

- **Goals:** `greenlight.php` loading, the typed builder, CLI flag parsing, and the documented precedence chain (defaults < config < CLI).
- **Key tasks:** `GreenlightConfig` builder with the PRD section 6 surface (paths, suites, workers, plugins list, coverage, failFast, randomizeOrder); an immutable resolved `Configuration` value object; config file locator and loader (with clear errors for missing/mistyped files); an owned minimal CLI parser (long/short options, no runtime dependency) and the `greenlight` command skeleton (`run` as default command, `--help`, `--version`, `list-tests` placeholder).
- **Deliverables:** `src/Config/`, `src/Cli/`; `bin/greenlight` executes against a real config file and prints the resolved plan-to-be.
- **Design decisions:** builder mutability (decision: fluent builder is mutable, `build()` produces the immutable object and the loader calls it, so user files stay terse); how suites compose filters; whether config can disable attributes globally (no; YAGNI).
- **Dependencies:** Phase 1 (config references core types like group names and plugin interfaces only loosely; can start once Phase 1 RFC is drafted rather than merged).
- **Risks:** the builder API is public DX and hard to change later. Mitigation: snapshot-test the builder's public method list; any change to it fails a test and forces a deliberate decision.
- **Validation:** acceptance tests loading fixture config files, asserting resolved `Configuration`; precedence matrix test (default vs config vs CLI for every overridable setting).

### Phase 3: test discovery

- **Goals:** static discovery of test classes and methods into an execution plan, with zero test code executed.
- **Key tasks:** classmap-based class enumeration honouring configured paths/suites; reflection-based attribute scan; data-set expansion (invoke static providers at plan time in the orchestrator, guarded by a documented exception: providers are the one thing discovery executes, they must be pure); filter engine (group/class/method/path, used by CLI flags and watch mode later); deterministic plan ordering with seeded class shuffle.
- **Deliverables:** `src/Discovery/`; `bin/greenlight list-tests` works end to end against `tests/Fixture/` suites.
- **Design decisions:** whether data-set providers run at discovery or execution time. Decision: discovery time in the orchestrator, so the plan knows every `TestId` up front for distribution and `--rerun-failed`; the cost (providers must not touch expensive resources) is documented and enforced with a time budget per provider.
- **Dependencies:** Phases 1 and 2.
- **Risks:** composer classmap coverage of odd autoloading setups. Mitigation: support explicit path globs as fallback; acceptance fixtures include a PSR-4-violating suite to pin behaviour.
- **Validation:** fixture suites with every attribute combination produce byte-identical plans across runs given the same seed.

### Phase 4: assertion model (Expect)

- **Goals:** the injected `Expect` service, core matcher set, diff rendering, soft expectations.
- **Key tasks:** `Expect::that()` chain, core matchers (equality, identity, type, exceptions, iterables, strings, numerics with delta), typed value renderers and diffing, `softly()`, failure objects feeding `FailureDetail` from Phase 1, the `ExpectationExtension` registration seam (interface only; plugin wiring arrives in Phase 7).
- **Deliverables:** `src/Expect/`, usable standalone; from this phase on, Greenlight's own tests use `Expect` instead of `assert()`.
- **Design decisions:** matcher failure messages format (decision: expectation sentence + typed diff, no PHPUnit-style constraint trees); how `and()` re-anchors the subject; whether negation is a modifier (`->not()->toBe()`) or paired matchers (decision: `not()` modifier, halves the matcher count).
- **Dependencies:** Phase 1 only. Fully parallel with Phases 2 and 3.
- **Risks:** diff quality is unbounded scope. Mitigation: v1 diffs cover scalars, arrays, enums, DateTime, and plain objects by reflection; everything else falls back to a documented export format, and better renderers are expectation-plugin territory.
- **Validation:** matcher spec suite (every matcher: pass case, fail case, message snapshot); self-hosted usage across the repo's own tests.

### Phase 5: execution engine

Split into two sub-phases with an interface freeze between them.

**5a: worker runtime and lifecycle (serial spine, no parallelism yet)**

- **Goals:** run one plan slice correctly in one process: instantiate test class with constructor injection, resolve harness scopes, run hooks, run test, tear down deterministically, emit Phase 1 events.
- **Key tasks:** the harness scope container (`perTest`/`perClass`/`perSuite`/`perRun`, reverse-order `Disposable` teardown, lazy-object instantiation); test class instantiation and injection resolution; hook execution (`#[Before]`/`#[After]` ordering rules); `#[Skip]`/`#[SkipUnless]`/`#[Timeout]`/`#[Retry]` semantics; the in-worker event emitter.
- **Deliverables:** `src/Harness/`, `src/Runner/Worker/`; `bin/greenlight run --workers=1` executes fixture suites end to end.
- **Design decisions:** injection resolution rules (exact type match only, no autowiring of arbitrary classes; unknown constructor parameter is a hard error naming the type); timeout mechanism in-worker (decision: cooperative `hrtime` checks plus orchestrator-side hard kill later in 5b; `pcntl_alarm` rejected as signal-unsafe with user code).
- **Dependencies:** Phases 1, 2, 3; Phase 4 for its own tests.
- **Risks:** this phase defines lifecycle semantics that the plugin API (Phase 7) exposes; getting teardown ordering wrong here is expensive later. Mitigation: RFC-002 (lifecycle and scopes) before implementation; exhaustive ordering tests including failure-during-teardown cases.
- **Validation:** lifecycle trace tests: fixture suites record every construct/hook/test/dispose call, assertions on exact order including failure paths.

**5b: orchestrator, wire protocol, parallel pool**

- **Goals:** the parallel-first runner from PRD section 7: process pool, binary-framed socket protocol, deterministic distribution, recycling, crash containment.
- **Key tasks:** process spawn/manage abstraction (owned, no symfony/process); framed protocol carrying Phase 1 wire types; deterministic distribution by class-name hash plus optional timing cache; worker recycling on test count and memory thresholds; `--bail` draining; `#[Isolated]` via dedicated worker; segfault/fatal containment and reporting.
- **Deliverables:** `src/Runner/Orchestrator/`, `src/Runner/Protocol/`; parallel runs are the default; RFC-003 (wire protocol) merged before implementation starts.
- **Design decisions:** protocol framing (length-prefixed msgpack-like owned encoding vs JSON frames; decision: length-prefixed JSON at first, encoding swappable behind an interface, measure before optimising); Windows story (no `pcntl`: `proc_open` worker spawning works everywhere, sockets via localhost TCP fallback).
- **Dependencies:** 5a complete and RFC-003 agreed. This is the highest-risk phase in the plan; nothing else should be scheduled against the same interfaces while it lands.
- **Risks:** the PRD names this the main Phase 1 technical risk. Property-based round-trip tests on the protocol; a chaos fixture suite (tests that exit, segfault via ffi, leak, write garbage to stdout) pins containment behaviour.
- **Validation:** same fixture suite produces identical aggregated results at `--workers=1`, `4`, and `16`; kill -9 on a worker mid-run yields a failed test attribution and a completed run.

### Phase 6: output capture and diagnostics

- **Goals:** per-test capture of stdout/stderr/notices/deprecations, attached to results, never corrupting reporter streams.
- **Key tasks:** stream interception installed around test execution in the worker; error-handler capture of notices/warnings/deprecations with configurable severity promotion (deprecations-as-failures switch); capture payloads on the wire and in `TestResult`.
- **Deliverables:** `src/Capture/`; escaped output from fixture tests appears in failure reports, not in the TTY stream.
- **Design decisions:** capture buffer limits (bounded, default 1 MiB per stream per test, truncation is marked); whether capture can be disabled per test (yes, `#[Test(capture: false)]` for tests debugging output themselves).
- **Dependencies:** 5a (worker), 5b (wire). Small phase, good first task for a new contributor or agent.
- **Risks:** interaction with user code that also uses `ob_*`. Mitigation: document the nesting contract; acceptance fixture that nests output buffers.
- **Validation:** chaos fixture suite from 5b now shows garbage-writing tests with clean reporter output.

### Phase 7: plugin architecture and execution context

- **Goals:** the PRD section 8 API: typed subscriber interfaces, live `TestContext`, capability-scoped plugin types, orchestrator/worker side documentation, provenance-logged outcome transformation.
- **Key tasks:** `TestContext` assembly in the worker; subscriber discovery from implemented interfaces; plugin registration via config; `HarnessProvider` wiring into the scope container; `ExpectationExtension` wiring into `Expect`; internal refactor: `#[Retry]` and `#[Skip]` re-implemented as internal plugins to prove the API carries real weight.
- **Deliverables:** `src/Plugin/`; RFC-004 (plugin API surface, the semver-scoped subset); at least two internal features shipped as plugins.
- **Design decisions:** exactly which Phase 1 types are plugin-visible (everything else stays `@internal`); subscriber ordering (priority integer, stable sort, documented); error policy when a plugin throws (fail the test with plugin attribution, never swallow).
- **Dependencies:** 5a, 5b, 6. The API is declared usable here but stable only in Phase 13, after two phases of internal dogfooding, per the PRD.
- **Risks:** freezing too early. Mitigation: `@experimental` annotation on the whole surface until Phase 13; CHANGELOG discipline for any change.
- **Validation:** the PRD's acceptance test, started now: a flaky-quarantine plugin built in `tests/Fixture/plugins/` using only the public API.

### Phase 8: reporters and output formats

- **Goals:** `tty`, `plain`, `junit` first; `jsonl`, `github`, `teamcity` second wave. All render from the identical result stream.
- **Key tasks:** `Reporter` consumption of the orchestrator event stream; TTY renderer (live per-worker progress, diffs, captured output, slowest-tests and memory summaries); deterministic plain renderer; JUnit XML writer; JSONL schema (documented in `docs/architecture/jsonl.md`, versioned); GitHub annotations; TeamCity messages.
- **Deliverables:** `src/Reporting/` plus one doc per format.
- **Design decisions:** JSONL schema versioning (an explicit `"v": 1` field, additive changes only); TTY terminal capability detection (owned, small; full ANSI handling is bounded to what the renderer uses).
- **Dependencies:** Phase 1 event model (frozen), 5b streaming. Each reporter is independent of every other: the cleanest parallelisation surface in the plan.
- **Risks:** TTY rendering is a scope sink. Mitigation: the TTY reporter gets a budget (no themes, no animations in v1) and everything fancy is plugin territory by construction.
- **Validation:** golden-file tests per reporter against a canned event stream; JUnit output validated against the de facto schema; PhpStorm consumes the TeamCity stream in a manual checklist.

### Phase 9: test doubles

- **Goals:** `greenlight/doubles` per PRD section 14: mock/stub/spy/fake, strict by default, lazy-object based, auto-verified and auto-disposed at test end.
- **Key tasks:** proxy generation for interfaces and non-final classes (cached per worker, invalidated by signature hash); `MockPlan` expectation DSL; argument matching integrated with `Expect` matchers; `Doubles` factory as a per-test harness service; auto-verify hooked into test teardown; spy assertion bridge (`toHaveReceived`); `Fake` marker interface.
- **Deliverables:** `src/Doubles/`, usable standalone with `Expect`.
- **Design decisions:** code generation strategy (eval'd generated classes vs written-to-disk cache; decision: written to `.greenlight/proxies/` for opcache benefit and debuggability); intersection/union type handling in generated signatures; readonly class doubling (out of scope v1, documented).
- **Dependencies:** Phase 4 (Expect integration points), 5a (per-test scope and teardown hook). Independent of 5b, 6, 7, 8: parallelisable against all of them.
- **Risks:** the PRD's named highest-effort component. Scope containment is contractual: interfaces and non-final classes only; no partial mocks; no static method mocking. Anything more is post-v1.
- **Validation:** double every interface in Greenlight's own `src/` as a smoke test; leak test: `WeakReference` to every double created in a fixture run, all collected after each test.

### Phase 10: coverage collection and export

- **Goals:** pcov and Xdebug drivers, per-worker collection with incremental orchestrator merge, lcov/Clover/Cobertura/HTML/JSON exports, baseline diff.
- **Key tasks:** driver abstraction and detection; per-worker collection windows around test execution; merge on the orchestrator as results stream (no end-of-run spike); the five exporters; `coverage:diff` command; opt-in per-test mapping.
- **Deliverables:** `src/Coverage/`; CI badge for Greenlight's own coverage.
- **Design decisions:** merge data structure (bitsets per file keyed by path hash, benchmarked against 1M-line fixture); HTML report scope (static, no JS framework, one page per file plus index).
- **Dependencies:** 5b (workers and wire). Independent of 7, 8, 9.
- **Risks:** Xdebug branch/path coverage wire volume. Mitigation: branch data batched per class rather than per test; measured before optimised.
- **Validation:** coverage of a fixture project matches `phpdbg`/reference numbers within documented semantics; lcov output consumed by `genhtml` without warnings.

### Phase 11: memory, isolation, and long-running safeguards

- **Goals:** make PRD section 12 enforceable rather than aspirational.
- **Key tasks:** `--detect-leaks` mode (`WeakReference` per test instance/doubles/per-test harness, named leak reports); recycling policy tuning (test-count and memory-threshold triggers, hysteresis); the CI `memory` job (10,000 synthetic tests, one worker, assert under 1 MB drift); `WeakMap` audit of every cache in the codebase; streaming audit of the orchestrator (bounded aggregates only).
- **Deliverables:** the memory CI gate, red until honest; leak-detection documentation.
- **Design decisions:** what counts as drift (RSS vs `memory_get_usage(true)`; decision: PHP-visible allocation for the gate, RSS recorded and graphed but not gated in v1).
- **Dependencies:** everything through Phase 9 (doubles are the likeliest leak source and must exist to be gated).
- **Risks:** the gate flakes on GC timing. Mitigation: explicit `gc_collect_cycles()` at measurement points; drift measured as a regression line over the run, not a single delta.
- **Validation:** intentionally leaky fixture test makes the gate fail; removing the leak makes it pass; `--detect-leaks` names the right test.

### Phase 12: self-hosting cutover

- **Goals:** Greenlight's suite runs under `bin/greenlight`; the bootstrap runner is deleted.
- **Key tasks:** port any remaining bootstrap-runner-isms; redefine `composer tests` to `bin/greenlight run`; run the suite in parallel in CI with coverage; delete `tools/bootstrap-runner.php`.
- **Deliverables:** a framework that tests itself in parallel with flat memory in CI, the Phase 1 exit criterion from the PRD, fully realised.
- **Design decisions:** none new; this phase exists to force honesty.
- **Dependencies:** Phases 4, 5, 6, 8 minimum (a runner, expectations, capture, at least the plain reporter). Doubles and coverage improve it but do not gate it; in practice this lands incrementally from Phase 5b onward and this phase is the formal cutover.
- **Risks:** circularity in debugging (a runner bug breaks the tests that would find it). Mitigation: the acceptance-test layer runs fixture suites as subprocesses and asserts on observable output, so a broken runner fails loudly rather than reporting green.
- **Validation:** CI green with `composer tests` invoking `bin/greenlight`; a deliberately broken matcher causes a red build (mutation-style spot check, manual).

### Phase 13: documentation, plugin API GA, release preparation

- **Goals:** v1.0: docs a newcomer can adopt from, the plugin API's semver promise, release mechanics.
- **Key tasks:** documentation site structure under `docs/` (getting started, configuration reference generated from the builder's signatures, attribute reference, plugin author guide, migration-from-PHPUnit conceptual guide); remove `@experimental` from the RFC-004 surface after a review pass; release workflow (tagging, signed phar as a stretch goal, Packagist); CHANGELOG backfill; the PRD section 16 benchmark suite published (vs PHPUnit + paratest on a public reference project).
- **Deliverables:** v1.0.0 tag.
- **Design decisions:** docs tooling (decision: plain markdown in-repo for v1, a site generator is post-v1); phar distribution (stretch, not a gate).
- **Dependencies:** all previous phases.
- **Risks:** benchmark claims that do not hold. The PRD promises published benchmarks; if 2x is not met, the number in the README is the measured one, whatever it is.
- **Validation:** an outside reader follows getting-started on a fresh project unaided; the flaky-quarantine and mutation-prototype plugins from the PRD acceptance test build against the GA API without patching core.

---

## Part 3: parallelisation with sub-agents

### The serial spine (never parallelise)

Phase 0, then 1, then 5a, then 5b, then 7. These five phases define, in order: the toolchain, the shared vocabulary of types, lifecycle semantics, the process/wire architecture, and the extension contract. Each is a global architecture decision; two agents making them concurrently produces two frameworks. One agent (or the lead session) owns the spine, and each spine phase ends with an interface freeze RFC that unblocks a fan-out.

### Safe parallel tracks

After the Phase 1 freeze (three tracks, zero shared files, deptrac keeps them honest):

- Track A: Phase 2 (Config + CLI) in `src/Config/`, `src/Cli/`.
- Track B: Phase 3 (Discovery) in `src/Discovery/`.
- Track C: Phase 4 (Expect) in `src/Expect/`. Longest track; start it first.

After the Phase 5a freeze:

- Track D: Phase 9 (Doubles) in `src/Doubles/`. Needs only the teardown hook signature and `Expect`; explicitly does not need the orchestrator.

After the Phase 5b freeze (event stream + wire schema fixed):

- Track E: Phase 6 (Capture). Small; also a good pipe-cleaner for the handoff process itself.
- Track F: Phase 8 reporters, one agent per reporter if desired. Reporters share only the read-only event stream and golden-test harness; six agents can build six reporters without a merge conflict.
- Track G: Phase 10 (Coverage).

Phase 11 is a convergence phase (audits across all components): one agent, after the fan-in. Phases 12 and 13 are integration and are inherently serial.

### Handoff points and coordination rules

1. Handoff artifact is a merged RFC plus frozen interfaces on `main`, never a description in a task prompt. A track agent starts from a commit containing the interfaces it consumes.
2. One component, one owner. No two agents write to the same `src/<Component>/` directory in the same window. Cross-component needs are expressed as a proposed interface change filed back to the spine owner, not edited in place.
3. deptrac is the tripwire. A track that "just needs one class" from another component will fail CI; that is working as intended and triggers a coordination conversation rather than a workaround.
4. Fixture suites are shared but append-only during fan-out: agents add fixture directories, never modify existing ones (other tracks' golden tests depend on them).
5. Convergence reviews at each fan-in: before 5b starts and before Phase 11, a review pass over merged tracks specifically for convention drift (naming, error message style, docblock discipline) while it is still cheap.
6. Each track sub-agent gets: the PRD, this plan's relevant phase section, the freezing RFC, and the component directory. It does not get licence to touch `src/Core/`.

### Suggested execution order (summary)

```
Phase 0 (bootstrap)                          spine
Phase 1 (core model, RFC-001)                spine
  ├── Phase 4 Expect                         track C (start first)
  ├── Phase 2 Config/CLI                     track A
  └── Phase 3 Discovery                      track B
Phase 5a (worker + lifecycle, RFC-002)       spine
  └── Phase 9 Doubles                        track D
Phase 5b (orchestrator + wire, RFC-003)      spine
  ├── Phase 6 Capture                        track E
  ├── Phase 8 Reporters (per-format)         track F
  └── Phase 10 Coverage                      track G
Phase 7 (plugin API, RFC-004)                spine
Phase 11 (memory gates)                      convergence
Phase 12 (self-hosting cutover)              integration
Phase 13 (docs + GA + v1.0)                  integration
```

The pressure points to respect: the result/event model (Phase 1) and the wire protocol (5b) are the two decisions everything else calcifies around, so they get RFCs and the most review; the plugin API is deliberately last to freeze; and self-hosting pressure is applied continuously from Phase 4 rather than saved for Phase 12.
