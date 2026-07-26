# <a href="https://ben-challis.github.io/greenlight/"><img src="docs/logo.svg" alt="Greenlight" width="292"></a>

[![CI](https://github.com/ben-challis/greenlight/actions/workflows/ci.yml/badge.svg?branch=main&event=push)](https://github.com/ben-challis/greenlight/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/ben-challis/greenlight/branch/main/graph/badge.svg)](https://app.codecov.io/gh/ben-challis/greenlight)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.4-777BB4?logo=php&logoColor=white)](composer.json)
[![License](https://img.shields.io/github/license/ben-challis/greenlight)](LICENSE)

**A parallel-first test framework for PHP 8.4+.**

Greenlight discovers a suite once, schedules test classes across a worker pool, recycles long-lived workers, isolates stateful tests, and ships with zero runtime dependencies.

[Read the documentation](https://ben-challis.github.io/greenlight/)

Greenlight runs its own test suite with `bin/greenlight run` across an automatically sized worker pool.

![Greenlight running its own test suite in parallel](docs/demo.gif)

## Highlights

* Parallel execution by default with dynamic, timing-aware scheduling.
* Resource limits for tests that share databases, services, or other finite dependencies.
* Worker recycling, leak detection, crash recovery, timeouts, and process isolation.
* Strict mocks, stubs, and spies with automatic verification.
* Typed expectations with rendered diffs.
* Deterministic terminal and CI output with repeatable reporters.
* Per-test attachments for structured values, text, bytes, and files.
* Stable CI sharding with `--shard=n/m`.
* Failed-first runs, seeded randomisation, watch mode, and repeat modes.
* Coverage through pcov or Xdebug, including diff coverage gates.
* Plain PHP test classes, attributes, constructor injection, and PHP configuration.
* First-party Symfony integration and a bundled PHPStan extension.

## A test

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

Tests are ordinary typed PHP classes. Attributes mark tests, hooks, data rows, retries, timeouts, skips, groups, resource requirements, and isolation. Constructor injection supplies fixtures, doubles, and plugin-provided services.

## Configuration

Configuration is PHP:

```php
<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->workers(count: 'auto');
```

The fluent API configures suites, workers, coverage, watch mode, randomisation, failure policy, deprecations, and plugins. See the [configuration reference](docs/configuration.md).

Run the suite:

```bash
vendor/bin/greenlight run
```

```text
Greenlight dev-main
PHP 8.4.14 | config: greenlight.php | workers: 11

3 tests, 3 passed, 3 expectations
Time: 0.121s
Workers: 1 spawned
```

Reporters are repeatable:

```bash
vendor/bin/greenlight run \
    --reporter=plain \
    --reporter=github
```

## Parallel execution

Greenlight uses one orchestrator and a pool of worker processes:

1. The orchestrator discovers the suite once.
2. It builds one execution plan.
3. Workers request test classes as capacity becomes available.
4. The orchestrator receives, verifies, and renders test events.
5. Timing data influences queue order on later runs.

### Scheduling

The scheduling unit is the test class. Method order within a class is preserved, but classes can run on any available worker. A class that requires a shared resource waits until capacity is available.

The orchestrator owns the queue. A worker receives a class after connecting, then requests another whenever it finishes. Work is not divided into fixed partitions before the run.

Queue order is:

1. Classes that failed on the previous run.
2. Classes with timing history, slowest first.
3. Classes without timing history, in discovery order.

Timings are stored in a per-project state file outside the repository and updated after each run. Seeded randomisation ignores timing data so the shuffled order remains reproducible.

If the first queued class is waiting for a resource, Greenlight may run classes that use other resources first. It reserves capacity for the waiting class so it cannot be postponed indefinitely.

### Worker lifecycle

The orchestrator starts workers and listens on a local socket. Workers exchange framed messages with it: class assignments go in, test events come out.

A worker bootstraps on its first assignment and reuses that process state across classes. Per-class reflection, hooks, and data sets are rebuilt for each assignment.

Test events stream as they occur. When a worker finishes an assignment, it also reports its own totals. The orchestrator cross-checks those totals against the events it received and fails the run on a mismatch.

Workers can leave the pool in several ways:

* **Recycling.** A worker that reaches its configured test count or memory ceiling returns unstarted work and exits. A replacement takes over.
* **Crash.** The in-flight test is reported as errored with captured stderr. Unstarted work is re-queued. The crashed test is not silently retried.
* **Timeout.** A test that exceeds its timeout receives a short grace period before the worker is terminated and replaced.
* **Isolation.** An `#[Isolated]` test runs in a new worker that is discarded afterwards.

A worker that never connects, or stops making progress with no test in flight, fails the run rather than stalling it.

### External resources

Channels and resource requirements solve different parallel-test problems.

Use a channel when each worker can have its own database, port range, temporary
directory, or other resource. Every worker receives a stable channel number from
`1` to the configured worker count. Concurrent workers never share a channel,
and a replacement inherits the released slot.

`GREENLIGHT_CHANNEL` and the injectable `TestChannel` service expose that number.
Greenlight assigns the slot; the application creates and manages the resource.

Use `#[RequiresResource]` when workers must use the same dependency but only a
limited number of classes can use it safely at once:

```php
#[RequiresResource('payments-sandbox')]
final class PaymentGatewayTest
{
    // ...
}
```

Without a configured limit, only one class that requires a resource runs at a
time. Set a larger limit in `greenlight.php` with
`resourceLimit('payments-sandbox', 3)`, or for one run with
`--resource-limit=payments-sandbox=2`.

A resource limit controls concurrency. It does not choose one concrete resource
instance or expose a lease identifier. Use a channel when one instance per
worker is available. If a smaller set of distinct instances needs allocating,
the application still needs its own allocator.

A test can use both. Its channel can select the worker's database while
`#[RequiresResource('payments-sandbox')]` limits access to a shared test service.

Greenlight schedules a whole class at once, so a method-level requirement applies
to the whole class assignment. Limits belong to one Greenlight run. Other
processes, worktrees, and shards do not share them; this is not a distributed
lock.

### Discovery and overhead

Greenlight token-parses `*Test.php` files for class names and attributes without loading them. Discovery results are cached by path, modification time, and size. Classes are autoloaded only after discovery, and discovery does not construct tests or execute hooks.

On the [published generated benchmarks](docs/benchmarks.md), Greenlight's best configuration is 2.5x to 7x faster than PHPUnit running directly across the documented suite shapes. Against ParaTest, results depend on workload; in the few-slow-tests case, Greenlight is about 1.6x faster.

For extremely trivial tests, worker startup can outweigh the work itself and a single worker may be faster.

Reproduce the benchmark harness with:

```bash
php tools/benchmark.php --with-phpunit
```

## Tests and fixtures

Lifecycle attributes:

* `#[Test]`
* `#[Before]`
* `#[After]`
* `#[Group]`
* `#[Skip]`
* `#[SkipUnless]`
* `#[Retry]`
* `#[Timeout]`
* `#[RequiresResource]`
* `#[Isolated]`

Data-driven tests use `#[DataSet]` or inline `#[DataRow]` attributes. Named rows appear in reports.

`#[Skip]` and `#[SkipUnless]` support built-in conditions for PHP versions, extensions, environment variables, operating system families, and other environment checks.

Expectations use a typed fluent chain:

```php
Expect::that($value)->toBe($expected);
Expect::that($items)->toHaveCount(3);
Expect::that($callback)->toThrow(RuntimeException::class);
Expect::that($result)->not()->toBeNull();
```

Failed expectations throw immediately with a rendered diff.

Use `eventually()` when state changes asynchronously:

```php
Expect::eventually(fn() => $orders->status($id))
    ->within(2.0)
    ->toBe(OrderStatus::Ready);
```

Use `consistently()` when a value must keep matching:

```php
Expect::consistently(fn() => $orders->countFor($id))
    ->pollEvery(0.050)
    ->for(0.5)
    ->toBe(1);
```

Both methods require a duration and poll every 25ms by default. A completed
matcher counts as one expectation, regardless of how many times Greenlight
calls the probe.

Harness services can be scoped per test, class, suite, or run. Services are created lazily and disposed in reverse order. Greenlight includes injectable `TempDirectory` and `EnvironmentSandbox` fixtures.

### Strict doubles

Tests can receive a per-test `Doubles` service and create:

* `mock()` for planned interactions and responses.
* `stub()` for satisfying a type while rejecting interactions.
* `spy()` for recording void-returning calls while rejecting value-returning calls.

Mocks fail immediately on unexpected calls and are verified automatically at the end of the test. Plans can return values or sequences, compute responses, throw exceptions, constrain arguments, and capture arguments.

The doubles API includes `Any`, `TypeMatcher`, `PredicateMatcher`, `ArgumentCaptor`, and `Fake`.

Tests that pass without verifying anything can be reported as risky. Use `#[NoExpectations]` for intentional exceptions and `--fail-on-risky` to fail risky tests.

## Local workflow

Selection and inspection:

* `--config=<path>`
* `--filter` and `--group`
* Exclusions by group, class, method, and path
* `list-tests`
* `--list-tests`, `--list-groups`, and `--list-suites`
* `--dry-run`

Iteration and debugging:

* `--failed`
* `--watch`
* `--workers=1`
* `--resource-limit=<name>=<n>`
* `--seed=N`
* `--repeat=N`
* `--repeat-until-failure`
* `--bail[=n]`
* `--detect-leaks`
* `--profile`
* `--no-ansi`
* `--verbose`

`--watch` cannot be combined with `--repeat` or `--repeat-until-failure`; invalid combinations exit with code `64`.

Per-test output capture records buffered output and PHP diagnostics on the test
that produced them. Direct writes to the `STDOUT` and `STDERR` stream resources
bypass capture. The `profile:report` command can replay a saved JSONL stream
into a profile report.

Tests and plugins can attach diagnostic data to an individual result. Greenlight
copies the content immediately, retains it on failure by default, and keeps it
outside the event stream. See
[test attachments](docs/attachments.md).

## CI, sharding, and coverage

Shard by class without shared coordination:

```bash
vendor/bin/greenlight run --shard=2/4
```

`--shard=n/m` uses a stable hash to assign disjoint class sets. It composes with filters and groups, and `list-tests` shows the resolved shard. Each shard applies resource limits separately.

Built-in reporters:

* `tty`
* `plain`
* `junit`
* `jsonl`
* `github`
* `teamcity`

CI controls include:

* `--fail-on-deprecation`
* `--fail-on-notice`
* `ignoreDeprecationsMatching()`
* `coverage:diff`

Retained attachments are written below `build/greenlight-artifacts` by default.
Upload that directory from a CI step that always runs, or use
`--artifacts-dir=<path>` to place it alongside other job outputs.

Coverage uses pcov or Xdebug and can be exported as JSON, LCOV, Clover, Cobertura, or HTML.

Exit codes are `0` for success, `1` for a failed or empty run, and `64` for usage errors.

## Integrations and extensions

Greenlight includes a first-party Symfony plugin and a `#[Service]` attribute for resolving services from Symfony's container. See [testing Symfony applications](docs/symfony.md).

Plugin extension points include:

* per-test lifecycle subscribers
* run-level event subscribers
* retry deciders
* harness providers
* service resolvers
* custom expectation matchers

Plugins receive the test instance, test metadata, and harness services at runtime.

The bundled PHPStan extension reads the Greenlight configuration, checks custom matcher names and arguments, and validates `#[DataSet]` providers and `#[DataRow]` values against test method signatures.

The `ide-helper` command generates autocomplete support with real signatures and accepts `--output=<path>`. The `completion` command provides Bash, Zsh, and Fish completion.

## Requirements and trade-offs

Greenlight requires PHP 8.4 or later and uses PHP 8.4 features including lazy objects and property hooks.

The runner uses core stream sockets and `proc_open`; it does not require a PHP extension. Coverage requires pcov or Xdebug.

Greenlight does not run PHPUnit suites directly. See [migrating from PHPUnit](docs/migrating-from-phpunit.md). Browser testing belongs in plugins rather than core.

## Documentation

Read the [Greenlight documentation](https://ben-challis.github.io/greenlight/) for
the guided website, or browse the Markdown sources directly:

* [Getting started](docs/getting-started.md)
* [Configuration reference](docs/configuration.md)
* [Attribute reference](docs/attributes.md)
* [Expectations](docs/expectations.md)
* [Test doubles](docs/test-doubles.md)
* [Writing plugins](docs/plugins.md)
* [Test attachments](docs/attachments.md)
* [Static analysis with PHPStan](docs/phpstan.md)
* [Testing Symfony applications](docs/symfony.md)
* [Migrating from PHPUnit](docs/migrating-from-phpunit.md)
* [Benchmarks](docs/benchmarks.md)
* [Architecture overview](docs/architecture/README.md)
* [Compatibility and public interfaces](docs/architecture/compatibility.md)
* [JSONL reporter schema](docs/architecture/jsonl.md)
* [Artifact storage architecture](docs/architecture/artifacts.md)
* [Coverage JSON schema](docs/architecture/coverage-json.md)
* [Temporal expectations](docs/architecture/temporal-expectations.md)
* [Worker lifecycle and scheduling](docs/architecture/worker-lifecycle.md)
* [Code conventions](docs/architecture/conventions.md)
* [Contributing guide](CONTRIBUTING.md)

## License

MIT. See [LICENSE](LICENSE).
