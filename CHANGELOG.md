# Changelog

All notable changes to Greenlight are documented here. The project is
pre-1.0: minor versions may contain breaking changes, each listed below.

## Unreleased

The initial feature set, built and self-hosted in one development cycle:

- Attribute-driven test authoring: `#[Test]`, `#[Before]`, `#[After]`,
  `#[DataSet]`, `#[Group]`, `#[Skip]`, `#[SkipUnless]`, `#[Retry]`,
  `#[Timeout]`, `#[Isolated]`, with constructor injection and no base class.
- Fluent PHP configuration (`greenlight.php`) with a typed builder,
  documented precedence (defaults, config file, CLI flags), and sub-builders
  for suites, coverage, and watch mode.
- Static test discovery with plan-time data-set expansion and deterministic
  seeded ordering.
- The `Expect` assertion service: fluent matcher chain, `not()` modifier,
  soft expectations, typed diff rendering, extension matchers.
- Parallel-first execution: an orchestrator/worker process pool over a
  framed socket protocol, deterministic distribution, worker recycling by
  test count or memory, crash containment with attribution, orchestrator-side
  hard kills for hung tests, and a summary cross-check that fails the run on
  any bookkeeping mismatch.
- Scoped harness services (per test, class, suite, or run) with lazy
  construction and deterministic reverse-order disposal.
- Test doubles that never guess: strict mocks with a planning DSL and
  mandatory explicit answers, inert stubs that error on any interaction,
  recording spies for void-returning methods, auto-verification at per-test
  scope close rendering like assertion failures.
- Per-test output capture (stdout plus notices, warnings, and deprecations)
  attached to results instead of polluting the report stream.
- Seven reporters over one event stream: tty, progress (a parallel-aware
  live display with one line per in-flight class), plain, junit, jsonl,
  github, teamcity.
- Coverage via pcov or xdebug with per-worker collection, incremental merge,
  five export formats, and a `coverage:diff` regression gate.
- A plugin API with live runtime context: test and run lifecycle
  subscribers, retry deciders, harness providers, expectation extensions,
  and reporters, with provenance-guarded outcome transformation.
- Watch mode: polling watcher, debounced re-runs, failed-first ordering.
- Memory discipline: `--detect-leaks` names tests whose instances survive,
  and CI gates a 10,000-test single-worker run at under 1 MiB of drift.
- Greenlight tests itself: the suite runs under `bin/greenlight run` across
  an auto-sized worker pool.
