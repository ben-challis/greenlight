# Greenlight

**Status: pre-release. Feature-complete for v1.0, self-hosted, not yet published to Packagist.**

Greenlight is an opinionated testing framework for PHP 8.4+, built around three ideas: tests are plain typed PHP that your tools already understand, parallel execution is the only code path rather than an add-on, and extensions get real runtime context instead of a sanitised events feed. It has zero runtime dependencies, so it never version-conflicts with the code under test.

Greenlight tests itself: this repository's suite runs under `bin/greenlight run` across an auto-sized worker pool.

## Authoring: typed PHP all the way down

- Attribute-driven tests with no base class: `#[Test]`, `#[Before]`, `#[After]`, `#[DataSet]`, `#[Group]`, `#[Skip]`, `#[SkipUnless]`, `#[Retry]`, `#[Timeout]`, `#[Isolated]`, and constructor injection for the services a test needs.
- The `Expect` assertion service: a fluent matcher chain with a `not()` modifier, soft expectations that collect several failures per test, typed diff rendering, and plugin-contributed matchers.
- Test doubles that never guess: mocks answer only what you configured, stubs exist to satisfy a type and error on any interaction, spies record void-returning calls. Every double is verified and torn down at the end of its test, and unmet plans read like assertion failures. Built on PHP 8.4 lazy objects, so doubling never executes a constructor.
- Static discovery with plan-time data-set expansion and deterministic seeded ordering: `--seed=N` reproduces any randomized run exactly.
- Fluent configuration in plain PHP (`greenlight.php`) with a typed builder, documented precedence, and sub-builders for suites, coverage, and watch mode. PHPStan checks your config file like any other code.

## Execution: parallel first, memory flat

- An orchestrator/worker process pool over a framed socket protocol, with deterministic distribution, crash containment that attributes the failure to the test that caused it, orchestrator-side hard kills for hung tests, and a summary cross-check that fails the run on any bookkeeping mismatch. Sequential is simply `workers: 1`, and hosts without `proc_open` fall back to it automatically.
- Memory stays flat over arbitrary suite sizes: workers recycle by test count or memory ceiling, CI gates a 10,000-test run at under 1 MiB of drift, and `--detect-leaks` names any test whose instance survives collection.
- Scoped harness services (per test, class, suite, or run) with lazy construction and deterministic reverse-order disposal, so expensive fixtures are built once and torn down predictably.
- Watch mode with debounced re-runs and failed-first ordering, and per-test output capture (stdout plus notices, warnings, and deprecations) attached to results instead of polluting the report stream.
- Coverage via pcov or Xdebug with per-worker collection, incremental merge, five export formats, and a `coverage:diff` regression gate.

## Extending: plugins with live context

- Lifecycle subscribers receive the actual test instance, its metadata, and its harness services, not a copy. Retry deciders, harness providers, run subscribers, custom reporters, and expectation extensions all hang off small capability interfaces, and outcome changes are provenance-guarded so reports stay trustworthy.
- Extension matchers stay statically checked: the bundled PHPStan extension reads your config files, reflects each matcher's signature, and fails analysis on name typos, wrong argument counts, and wrong argument types.
- Six reporters over one event stream: a parallel-aware live terminal display, plain, junit, jsonl, github, teamcity. Reporters are ordinary plugin implementations; yours plugs in the same way.

## Requirements

PHP 8.4 or later, nothing else. Lazy objects, property hooks, and asymmetric visibility are load-bearing in the design, so older runtimes are not supported. The parallel runner uses core stream sockets and `proc_open` and needs no extension; `ext-pcov` or Xdebug enable coverage.

## Documentation

- [Getting started](docs/getting-started.md)
- [Configuration reference](docs/configuration.md)
- [Attribute reference](docs/attributes.md)
- [Writing plugins](docs/plugins.md)
- [Migrating from PHPUnit](docs/migrating-from-phpunit.md)
- [Product Requirements Document](docs/PRD.md) describes the full design.
- [Build plan](docs/plan/README.md) and [RFCs](docs/rfcs/) record how and why it was built this way.
- [Contributing guide](CONTRIBUTING.md) covers the rules for changes.

## License

MIT. See [LICENSE](LICENSE).
