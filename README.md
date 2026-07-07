# Greenlight

**Status: pre-release. Feature-complete for v1.0, self-hosted, not yet published to Packagist.**

Greenlight is an opinionated testing framework for PHP 8.4+. It combines an attribute-driven authoring model that PHPStan and IDEs understand natively, an orchestrator/worker runner where parallel execution with flat memory is the core code path, a test-double library that never guesses (built on PHP 8.4 lazy objects with mandatory end-of-test verification), and a plugin API whose interception points receive the actual test instance and its harness. Write typed tests, run them in parallel with flat memory, and extend the runner without fighting it.

Greenlight tests itself: this repository's own suite runs under `bin/greenlight run` across an auto-sized worker pool.

## Why Greenlight

- Every construct a test author touches is plain typed PHP that static analysis and refactoring tools understand with no framework-specific plugins.
- Parallel execution is the default and the only code path; sequential runs are simply `workers: 1`.
- Memory stays flat over arbitrary suite sizes: CI gates a 10,000-test run at under 1 MiB of drift, and `--detect-leaks` names any test whose instance survives.
- Test doubles never guess: mocks answer only what was configured, stubs satisfy types and error on any interaction, and every double is verified and torn down at the end of its test.
- Plugins get real runtime context: the test instance, its metadata, and its harness services.

## Documentation

- [Getting started](docs/getting-started.md)
- [Configuration reference](docs/configuration.md)
- [Attribute reference](docs/attributes.md)
- [Writing plugins](docs/plugins.md)
- [Migrating from PHPUnit](docs/migrating-from-phpunit.md)
- [Product Requirements Document](docs/PRD.md) describes the full design.
- [Build plan](docs/plan/README.md) and [RFCs](docs/rfcs/) record how and why it was built this way.
- [Contributing guide](CONTRIBUTING.md) covers the rules for changes.

## Requirements

PHP 8.4 or later. Lazy objects, property hooks, and asymmetric visibility are load-bearing in the design, so older runtimes are not supported. `ext-pcntl` and `ext-sockets` are recommended for the parallel runner; `ext-pcov` or Xdebug enable coverage.

## License

MIT. See [LICENSE](LICENSE).
