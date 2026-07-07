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
- Six reporters over one event stream: tty (a parallel-aware live display
  with one line per in-flight class, finalised in place as classes
  complete), plain, junit, jsonl, github, teamcity.
- Coverage via pcov or xdebug with per-worker collection, incremental merge,
  five export formats, and a `coverage:diff` regression gate.
- A plugin API with live runtime context: test and run lifecycle
  subscribers, retry deciders, harness providers, expectation extensions,
  and reporters, with provenance-guarded outcome transformation.
- An `ide-helper` command writing the duplicate-declaration file IDEs
  index so extension matchers autocomplete with real signatures, generated
  from the same matcher map the PHPStan extension enforces.
- A `completion` command printing static shell completion scripts for
  bash, zsh, and fish to stdout, generated from the same option table the
  argument parser reads, covering command names, flags, and the value
  lists for `--reporter` and `--workers`.
- A PHPStan extension (`extension.neon`) that reads extension matchers from
  your config files and type-checks matcher calls on the expectation chain,
  covering name typos, argument counts, and argument types.
- Watch mode: polling watcher, debounced re-runs, failed-first ordering.
- A reproducible benchmark harness (`tools/benchmark.php`) with published
  numbers in docs/benchmarks.md, including the losses, and a decision
  record deferring an Infection adapter until per-test coverage mapping
  ships.
- CI gates: `--fail-on-deprecation` and `--fail-on-notice` fail passed
  tests on captured diagnostics (with a config allow-list for dependency
  noise), and risky-test detection lists passed tests that verified no
  expectations, upgraded to failures by `--fail-on-risky`, with
  `#[NoExpectations]` as the explicit opt-out.
- Suite sharding: `--shard=<n>/<m>` selects disjoint class slices by
  stable hash for coordination-free CI splitting.
- A discovery cache: per-file plan entries keyed by path, mtime, and size
  under the system temp dir, halving cold discovery on large suites and
  speeding every watch iteration; any doubt falls back to parsing.
- Demand-driven scheduling: workers pull one class at a time from the
  orchestrator's queue and are reused across assignments, with the queue
  ordered longest first from durations recorded in the run state (failed
  classes still first, seeded runs untouched); cumulative recycling budgets
  now span assignments, and per-run harness services keep worker-lifetime
  semantics across them. On the self-hosted suite this cut the makespan
  spread from 6.2s to 2.0s at four workers.
- Run profiling: `--profile` appends worker utilisation, boot latency,
  makespan spread, and the slowest classes after the summary, and
  `profile:report --input=<jsonl>` reproduces the block offline from a
  saved artifact; class events now carry the executing worker's id.
- Inline data sets: repeatable `#[DataRow([args], label: ...)]` rows,
  combinable with a `#[DataSet]` provider under one key space.
- Test selection: `--filter` id patterns (substring or `*` wildcards, case
  insensitive, data-set labels included) and `--failed` re-running the
  previous run's failures from state recorded on every run, with failed
  classes ordered first on plain runs; the human reporters end with a
  slowest-tests block when anything crossed 200 ms.
- Memory discipline: `--detect-leaks` names tests whose instances survive,
  and CI gates a 10,000-test single-worker run at under 1 MiB of drift.
- Graceful shutdown on SIGINT and SIGTERM (requires ext-pcntl): the first
  signal drains workers, prints the summary for completed tests, records
  the run state, and exits 130 or 143; a second signal terminates
  immediately, and watch mode restores the terminal on the way out.
- Greenlight tests itself: the suite runs under `bin/greenlight run` across
  an auto-sized worker pool.
