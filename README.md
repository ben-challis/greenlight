# Greenlight

**Status: pre-alpha, under active development, not yet usable.**

Greenlight is an opinionated testing framework for PHP 8.4+. It combines an attribute-driven authoring model that PHPStan and IDEs understand natively, an orchestrator/worker runner where parallel execution with flat memory is the core code path, a lifecycle-safe test-double library built on PHP 8.4 lazy objects with mandatory end-of-test teardown, and a plugin API whose interception points receive the actual test instance and its harness. Write typed tests, run them in parallel with flat memory, and extend the runner without fighting it.

## Why Greenlight

- Every construct a test author touches is plain typed PHP that static analysis and refactoring tools understand with no framework-specific plugins.
- Parallel execution is the default and the only code path; sequential runs are simply `workers: 1`.
- Memory stays flat over arbitrary suite sizes: a 50,000-test run uses no meaningfully more peak memory per worker than a 500-test run, enforced in CI.
- Test doubles are torn down at the end of every test by design, with a leak-detection mode that names the test that leaked.
- Plugins get real runtime context: the test instance, its metadata, and its harness services.

## Requirements

PHP 8.4 or later. Lazy objects, property hooks, and asymmetric visibility are load-bearing in the design, so older runtimes are not supported.

## Documentation

- [Product Requirements Document](docs/PRD.md) describes the full design.
- [Build plan](docs/plan/README.md) describes the phased implementation.
- [Contributing guide](CONTRIBUTING.md) covers the rules for changes.

## License

MIT. See [LICENSE](LICENSE).
