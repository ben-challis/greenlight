# Greenlight

**A fast, parallel-first test framework for PHP 8.4+.**

Greenlight is built for teams that want shorter feedback loops, dependable CI runs, and tests that fail when something unexpected happens.

It runs suites in parallel by default, keeps long-running workers healthy, isolates only the tests that need a clean process, and ships with **zero runtime dependencies**, so the test framework cannot conflict with the application it is testing.

> [!NOTE]
> Greenlight is not yet released.

Greenlight already runs its own test suite using `bin/greenlight run` across an automatically sized worker pool.

![Greenlight running its own test suite in parallel](docs/demo.gif)

## At a glance

- Parallel execution by default with an automatically sized worker pool.
- Dynamic scheduling that keeps workers busy and prioritises historically slow classes.
- Worker recycling and leak detection for stable long-running suites.
- Per-test or per-class process isolation.
- Strict mocks, stubs, and spies with automatic verification.
- Deterministic terminal and CI reporting.
- Stable, coordination-free CI sharding with `--shard=n/m`.
- Seeded randomisation for reproducible ordering.
- Failed-first workflows with `--failed` and watch mode.
- Flake hunting with `--repeat` and `--repeat-until-failure`.
- Coverage gates through pcov or Xdebug.
- Typed expectations with rendered diffs.
- Plain PHP test classes, attributes, constructor injection, and PHP configuration.
- First-party Symfony integration.
- Zero runtime dependencies.

## Why choose Greenlight

### Faster feedback without a parallel wrapper

Parallel execution is not an add-on in Greenlight. It is the core execution model.

Greenlight discovers the suite once, builds one execution plan, and streams work to a pool of workers. Workers pull the next class as soon as they are free, while timing data from previous runs helps schedule slower classes earlier.

The result is better worker utilisation, less duplicated startup work, and faster feedback on the kinds of suites that dominate real applications: database tests, filesystem tests, HTTP tests, container bootstrapping, and application integration tests.

Need to debug sequentially? Use `--workers=1`. It follows the same execution path, so you do not have to switch runners or change how the suite behaves.

### Long test runs stay healthy

Large suites often accumulate memory through application caches, fixtures, connection pools, or test state that survives longer than intended.

Greenlight bounds that damage by recycling workers after a configurable test count or memory ceiling. It can also help identify the underlying problem: `--detect-leaks` names tests whose instances survive collection.

Greenlight holds itself to the same standard. Results stream instead of accumulating, and CI gates a 10,000-test single-worker run at under 1 MiB of framework-owned memory drift.

### Isolation where you need it, not everywhere

Some tests need a fresh process because they mutate global state, exercise shutdown behaviour, change process-wide configuration, or load code that cannot be unloaded safely.

Mark a test or class with `#[Isolated]` and Greenlight runs it in a dedicated fresh worker, then discards that worker. The rest of the suite continues in the normal parallel pool.

A handful of stateful tests no longer has to make the whole suite slow.

### Test doubles that catch accidental behaviour

Greenlight’s doubles are strict by default.

A mock answers only the interactions the test planned. An unexpected call fails immediately. A planned call that never happens also fails automatically when the test scope closes, so there is no verification step to remember.

Stubs satisfy a type but reject interactions. Spies record void-returning calls for later inspection and fail if a value-returning method is called, so they cannot invent a return value. Passing tests that verified nothing can be marked risky, and `--fail-on-risky` can promote those cases to failures.

This makes refactors safer: an unplanned collaborator call cannot quietly return `null` and leave the suite green.

### Predictable output locally and in CI

Parallel test output is captured per test and written by a single orchestrator. Echoes, warnings, notices, and diagnostics stay attached to the test that produced them instead of being interleaved across workers.

Greenlight produces deterministic output through terminal, plain, JUnit, JSONL, GitHub, and TeamCity reporters. Multiple reporters can consume the same run, so one execution can serve developers, CI logs, dashboards, and custom tooling.

### No dependency conflicts

Greenlight has one runtime requirement:

```text
php: >=8.4
```

There are no framework dependencies to resolve inside your application’s Composer graph. Greenlight cannot force a conflicting version of a parser, console package, diff library, or other shared dependency into the project.

The trade-off is deliberate: Greenlight requires PHP 8.4+, does not run PHPUnit suites directly, and leaves browser testing to plugins rather than core.

## What a test looks like

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class PriceTest
{
    #[Test]
    #[DataRow(['9.99', 2, '19.98'], label: 'two units')]
    #[DataRow(['0.50', 3, '1.50'], label: 'three small units')]
    public function multipliesLineTotals(string $unit, int $quantity, string $expected): void
    {
        $total = Price::fromString($unit)->times($quantity);

        Expect::that($total->format())->toBe($expected);
    }

    #[Test]
    public function rejectsNegativeQuantities(): void
    {
        Expect::that(static function (): void {
            Price::fromString('9.99')->times(-1);
        })->toThrow(\InvalidArgumentException::class, matching: '/quantity/');
    }
}
```

Greenlight tests are ordinary typed PHP classes. Attributes mark tests and data rows, expectations start with `Expect::that()`, and constructor injection supplies stateful services such as fixtures, test doubles, and plugin-provided harness services.

Because tests remain plain PHP, PHPStan, Rector, IDE refactorings, and other standard tooling work as expected.

## Configuration is PHP too

Greenlight configuration lives in a PHP file at the project root, so it can be reviewed, refactored, and analysed like the rest of the codebase.

```php
<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->workers(count: 'auto');
```

The same fluent API configures named suites, coverage, watch mode, seeded randomisation, fail-fast behaviour, deprecation policy, and more. See the [configuration reference](docs/configuration.md) for the full surface.

Run the suite:

```bash
vendor/bin/greenlight run
```

```text
Greenlight dev-main | PHP 8.4.14 | config: greenlight.php | workers: 11

3 tests, 3 passed, 3 expectations
Time: 0.110s
Workers: 1 spawned
```

In an interactive terminal, Greenlight shows live per-worker activity. In CI, the plain reporter emits deterministic append-only output:

```text
PASS App\Tests\PriceTest::multipliesLineTotals[two units] (0.001s)
PASS App\Tests\PriceTest::multipliesLineTotals[three small units] (0.000s)
PASS App\Tests\PriceTest::rejectsNegativeQuantities (0.000s)
```

Reporters are repeatable, so one run can emit several formats:

```bash
vendor/bin/greenlight run \
    --reporter=plain \
    --reporter=junit \
    --reporter=jsonl
```

## Built for parallel execution

Traditional PHP parallelism is commonly provided by a wrapper around a sequential test runner. Each process repeats bootstrap and discovery, while the wrapper coordinates work and reconstructs output from the outside.

Greenlight starts with a different architecture:

1. The orchestrator discovers tests once.
2. It builds a single execution plan.
3. Workers pull test classes as capacity becomes available.
4. A timing cache schedules historically slower classes earlier.
5. The orchestrator captures and renders every result.

This design also makes shared-resource allocation predictable. Every worker receives a stable channel number from `1` to the worker count. Concurrent tests never share a channel, and a replacement worker inherits the released slot.

Use `GREENLIGHT_CHANNEL` or the injectable `TestChannel` service to select a per-worker database, port range, temporary directory, or other resource. Greenlight tells the test which resource slot belongs to it; your application remains in control of creating and managing that resource.

## Fast discovery, low runner overhead

Greenlight token-parses `*Test.php` files for class names and attributes without loading them. Discovery results are cached by path, modification time, and size.

A class is autoloaded only after discovery has identified it, and discovery never constructs a test or executes a hook. This reduces bootstrap work and avoids unnecessary side effects before the run begins.

On the [published generated benchmarks](docs/benchmarks.md), Greenlight’s best configuration is 2.5x to 7x faster than PHPUnit running directly across the four documented suite shapes. Against ParaTest, the result depends on the workload; in the few-slow-tests case, Greenlight is about 1.6x faster. Most of the margin comes from lower per-test runner overhead, not parallelism alone.

The benchmark documentation also publishes the unfavourable case: for extremely trivial tests, worker startup can cost more than the work itself, and a single worker may be faster.

Reproduce the benchmark harness with:

```bash
php tools/benchmark.php --with-phpunit
```

## Strict test doubles

Tests receive a per-test `Doubles` service through constructor injection and can create:

- `mock()` for explicitly planned interactions and responses.
- `stub()` for satisfying a type while rejecting all interactions.
- `spy()` for recording void-returning calls while rejecting value-returning calls.

Mocks fail immediately on unexpected interactions and are verified automatically when the test ends. Plans can return values, return sequences, compute answers, throw exceptions, constrain arguments, and capture arguments for later assertions. The doubles API includes `Any`, `TypeMatcher`, `PredicateMatcher`, `ArgumentCaptor`, and `Fake` for flexible matching, capture, and lightweight fakes.

The runner can also identify tests that passed without verifying anything. Use `#[NoExpectations]` to document intentional exceptions.

## Writing tests

Greenlight includes attributes for the full test lifecycle:

- `#[Test]`
- `#[Before]`
- `#[After]`
- `#[Group]`
- `#[Skip]`
- `#[SkipUnless]`
- `#[Retry]`
- `#[Timeout]`
- `#[Isolated]`

`#[Skip]` and `#[SkipUnless]` can use built-in conditions for PHP versions, loaded extensions, environment variables, operating system families, and other environment checks.

Data-driven tests can use provider methods or inline rows:

- `#[DataSet]`
- `#[DataRow]`

Named data keys appear in reports, making failures easier to identify.

Expectations use a fluent typed chain:

```php
Expect::that($value)->toBe($expected);
Expect::that($items)->toHaveCount(3);
Expect::that($callback)->toThrow(RuntimeException::class);
Expect::that($result)->not()->toBeNull();
```

Failed expectations throw immediately with a rendered diff.

Harness services can be scoped per test, class, suite, or run. Expensive fixtures are created lazily and disposed in reverse order. Greenlight also provides injectable `TempDirectory` and `EnvironmentSandbox` fixture services for isolated filesystem and environment-variable tests.

## Symfony integration

Greenlight includes a first-party Symfony plugin and a `#[Service]` attribute for integrating tests with Symfony’s service container. See [testing Symfony applications](docs/symfony.md).

## Local development and debugging

Greenlight includes the workflows needed for fast iteration:

- `--config=<path>` to select a configuration file.
- `--filter` and `--group` to select tests.
- Exclusion flags for groups, classes, methods, and paths.
- `--failed` to rerun failures from the previous run.
- `--watch` for debounced reruns with failed-first ordering. It cannot be combined with `--repeat` or `--repeat-until-failure`; invalid combinations exit with code `64`.
- `--workers=1` for sequential debugging on the same runner path.
- `--seed=N` to reproduce randomised order exactly.
- `--repeat=N` and `--repeat-until-failure` to investigate flakes.
- `--bail[=n]` to stop after a chosen number of failures.
- The top-level `list-tests` command, plus `--list-tests`, `--list-groups`, and `--list-suites`, to inspect selection without running it.
- `--dry-run` to print resolved configuration.
- `--profile` to report worker utilisation, boot latency, and the slowest classes.
- `--no-ansi` and `--verbose` for output control and additional diagnostics.

Per-test output capture keeps stdout and PHP diagnostics attached to the result that produced them. The `profile:report` command can replay a saved JSONL stream into a profile report, so profiling data captured in CI can be inspected later.

## CI, sharding, and reporting

Greenlight can split a suite across CI machines without shared state:

```bash
vendor/bin/greenlight run --shard=2/4
```

`--shard=n/m` assigns whole classes using a stable hash. Every machine independently computes the same disjoint partition, and the flag composes with filters and groups. `list-tests` shows exactly which tests belong to each shard.

Available reporters:

- `tty`
- `plain`
- `junit`
- `jsonl`
- `github`
- `teamcity`

CI controls include:

- `--fail-on-deprecation`
- `--fail-on-notice`
- `ignoreDeprecationsMatching()` for known dependency deprecations
- coverage through pcov or Xdebug
- `coverage:diff` for regression gating

Coverage can be exported as JSON, LCOV, Clover, Cobertura, or HTML.

Exit codes are deterministic: `0` for success, `1` for a failed or empty run, and `64` for usage errors.

## Extending Greenlight

Plugins receive live runtime context, including the test instance, test metadata, and harness services.

Extension points include:

- per-test lifecycle subscribers
- run-level event subscribers
- retry deciders
- harness providers
- service resolvers
- custom expectation matchers

Custom expectation matchers remain statically checked. The bundled PHPStan extension reads the Greenlight configuration, detects matcher name or argument errors, and validates `#[DataSet]` providers and `#[DataRow]` values against test method signatures.

The `ide-helper` command generates autocomplete support with real signatures and accepts `--output=<path>` when the generated file should be written somewhere specific. See [static analysis with PHPStan](docs/phpstan.md).

Shell completion is available for Bash, Zsh, and Fish through the `completion` command.

## Requirements and trade-offs

Greenlight requires PHP 8.4 or later. It uses PHP 8.4 features, including lazy objects and property hooks, and intentionally targets one modern runtime rather than carrying compatibility layers for older PHP versions.

The parallel runner uses core stream sockets and `proc_open`; it does not require an extension.

Coverage requires either:

- `ext-pcov`
- Xdebug

Greenlight does not run PHPUnit suites directly. See [migrating from PHPUnit](docs/migrating-from-phpunit.md) for migration guidance.

## Documentation

- [Getting started](docs/getting-started.md)
- [Configuration reference](docs/configuration.md)
- [Attribute reference](docs/attributes.md)
- [Writing plugins](docs/plugins.md)
- [Static analysis with PHPStan](docs/phpstan.md)
- [Testing Symfony applications](docs/symfony.md)
- [Migrating from PHPUnit](docs/migrating-from-phpunit.md)
- [Benchmarks](docs/benchmarks.md)
- [JSONL reporter schema](docs/architecture/jsonl.md)
- [Coverage JSON schema](docs/architecture/coverage-json.md)
- [Code conventions](docs/architecture/conventions.md)
- [Contributing guide](CONTRIBUTING.md)

## License

MIT. See [LICENSE](LICENSE).
