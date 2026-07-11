# Greenlight

**A parallel test framework for PHP 8.4+.**

Greenlight runs test suites in parallel by default and ships with zero runtime dependencies, so it never version-conflicts with the application under test.

Long runs stay healthy because workers recycle themselves before memory creeps. Tests that need a clean process get one without slowing down the rest of the suite. Output is captured per test, and reports come out the same way on a laptop as they do in CI.

> [!NOTE]
> Greenlight is not yet released.

Greenlight already tests itself. This repository's suite runs under its own `bin/greenlight run` across an auto-sized worker pool.

![Greenlight running its own test suite in parallel](docs/demo.gif)

## Why Greenlight?

PHP's test tooling has a long history, and Greenlight exists because of that history rather than in spite of it. PHPUnit defined how PHP developers write tests and has carried the ecosystem for more than twenty years. Most of the friction teams feel with it today traces back to decisions that were correct for single-core PHP in 2004, compounded by the weight of serving every PHP codebase since.

Greenlight starts over at the runner. These are the pitfalls it was built against, and they shaped the execution model before the assertion API existed.

### Parallelism has always been an add-on

PHPUnit executes tests sequentially in a single process. Running a suite in parallel means wrapping it: ParaTest, and Pest's `--parallel` mode built on ParaTest, spawn several PHPUnit processes and split the suite among them. ParaTest does this about as well as an external tool can, but it sits outside the framework. Every spawned process repeats framework bootstrap and discovery, the wrapper only sees results at process granularity, and output from concurrent processes has to be reassembled afterwards.

In Greenlight the parallel pool is the runner, not an accessory. The orchestrator discovers tests once, builds one plan, and streams work to workers over sockets. Workers pull the next class as they finish the previous one, so a slow class does not strand the rest of the pool, and a timing cache from the last run schedules the slowest classes first. The orchestrator is the sole stdout writer and output is captured per test, so when parallel workers all have something to say, stray echoes, warnings, and notices are attributed to the test that produced them rather than interleaved into the console output of whichever process got there first. Sequential execution is `--workers=1` on the same code path, which is why attaching a debugger never requires switching runners.

### Splitting shared resources is left to convention

Parallel tests compete for databases, ports, and temp directories. The ecosystem handles this with environment tokens (ParaTest's `TEST_TOKEN`, Laravel's parallel testing hooks) and per-team wiring on top. The other common answer, wrapping each test in a database transaction, breaks as soon as the code under test crosses a connection or process boundary.

Greenlight makes the mechanism part of the contract and calls it a channel. Every worker occupies a numbered slot between 1 and the worker count. Two concurrently running tests never share a channel, and a replacement worker inherits the freed slot, so a database migrated for channel 3 stays valid for the whole run even as workers recycle. Tests read the number from the `GREENLIGHT_CHANNEL` environment variable or the injectable `TestChannel` service.

### Discovery does too much work up front

PHPUnit discovers tests by loading every test file and reflecting on the classes inside, so a large codebase pays autoload cost and side effects before the first test runs, on every run.

Greenlight token-parses `*Test.php` files for class names and attributes without loading them, and caches the per-file result keyed by path, mtime, and size. A class is autoloaded only after the scan has found it, and discovery never constructs a test or runs a hook. Execution stays cheap too: on the [published benchmarks](docs/benchmarks.md), most of Greenlight's margin over PHPUnit comes from lower runner overhead per test rather than from parallelism alone.

### Isolation is all or nothing

Some tests genuinely need a fresh process because they mutate globals, exercise shutdown behaviour, or load code that cannot be unloaded. PHPUnit's process isolation spawns a new process per test, which is slow enough that teams either scope it with great care or switch it off and absorb the state leaks.

In Greenlight, `#[Isolated]` gives one test or class a dedicated fresh worker that is discarded afterwards, while the rest of the suite keeps running in the pool. A handful of isolated tests costs a handful of process spawns instead of the suite's speed.

### Doubles answer questions nobody asked

The ecosystem's mocking tools default to being agreeable. A PHPUnit `createMock()` double lets any unconfigured method succeed and returns an automatic value: null, zero, an empty string, or another generated stub. That default exists to keep test setup cheap, but it means a call the test never planned succeeds silently. Rename a method, misspell an expectation, or add a new collaborator call during a refactor, and the double hands back null instead of objecting. The test stays green while asserting something the author never wrote, and the gap only surfaces later, if at all. Verification has its own version of the problem: Mockery only checks expectations if `close()` runs, so a forgotten integration detail turns every mock into a stub.

Greenlight's doubles are strict by default. A mock answers only the interactions the test planned, and an unexpected call fails on the spot with the same rendering quality as an expectation failure. A stub satisfies a type and errors on any interaction at all, and a spy records void-returning calls for later inspection. Verification is not a step anyone can forget: it happens automatically when the per-test scope closes, and a planned call that never arrived fails the test there. The runner also flags passing tests that verified nothing as risky, `--fail-on-risky` turns them into failures, and `#[NoExpectations]` records the deliberate exceptions.

### Sharding needs coordination

Splitting a suite across CI machines usually means hand-maintained directory lists that drift out of balance, or timing-based splitters that need shared state between machines. Greenlight's `--shard=n/m` selects whole classes by a stable hash, so every machine computes the same disjoint partition independently. Nothing has to be shared or synchronised, the flag composes with `--filter` and `--group`, and `list-tests` shows exactly which tests landed in a shard.

### Long runs drift

When a long test run eats memory, the leak usually lives in the tests or the application: static caches, connection pools, fixtures held past their test. No runner can fix that from the outside. What a runner chooses is whether to bound the damage, whether to help find the culprit, and whether it adds drift of its own on top.

Greenlight takes a position on all three. Worker recycling after a memory ceiling or a test count is an admitted backstop for leaky test code, not a cure, and `--detect-leaks` exists so the leaking test can be named and fixed rather than paid for on every run. The framework holds itself to a stricter standard than it holds your tests: results stream instead of accumulating, and CI gates a 10,000-test run at under 1 MiB of framework-owned drift, so recycling never gets to hide Greenlight's own leaks.

### Framework weight

PHPUnit is a large install because it has spent two decades serving every kind of PHP project, which is a fair reason to be large. The cost that remains is practical: its dependencies resolve in the same Composer graph as your application's, so version conflicts happen.

Greenlight's `require` section contains one line: `php: >=8.4`. There is nothing to version-conflict with the code under test. The trade is stated rather than hidden: it does not support PHP below 8.4 or run PHPUnit suites, and concerns like browser testing belong in plugins rather than core.

## Features

* Parallel by default, with an auto-sized worker pool.
* Worker recycling and leak detection keep memory flat over long suites.
* Process isolation for tests or classes that need clean state.
* Deterministic CI output through plain, JUnit, JSONL, GitHub, and TeamCity reporters.
* Seeded randomisation, so any ordering can be reproduced exactly.
* `--failed` and watch mode for failed-first workflows.
* Coverage gates through pcov or Xdebug.
* Strict test doubles that fail on unplanned or unverified behaviour.
* Typed expectations with rendered diffs and a broad built-in matcher set.
* Built-in skip conditions for PHP version, extension, OS, env var, function, and class checks.
* First-party fixtures: temp directories and environment sandboxing.
* Flake hunting with `--repeat` and `--repeat-until-failure`.
* Test code is plain PHP: attributes, typed classes, constructor injection, and PHP configuration.
* Zero runtime dependencies, so the framework never version-conflicts with the code under test.

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

Greenlight tests are normal typed PHP classes. Attributes mark tests and data rows, assertions start from `Expect::that()`, and constructor injection provides stateful services such as fixtures, test doubles, and plugin-provided harness services.

Because a test class is ordinary PHP, standard tooling (PHPStan, Rector, an IDE's refactorings) works on it like any other code.

## Configuration

Configuration is a PHP file at the project root. It can be reviewed, refactored, and checked by PHPStan like the rest of the codebase.

```php
<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->workers(count: 'auto');
```

The same fluent API covers the rest of the runner: `suite()` declares named suites with per-suite filtering, `coverage()` selects a driver and export formats, `watch()` tunes the watch-mode debounce, `randomizeOrder()` opts into seeded random ordering, `failFast()` stops the run on first failure, and `ignoreDeprecationsMatching()` exempts known dependency deprecations from `failOnDeprecation()`. The full surface is in the [configuration reference](docs/configuration.md).

Run the suite:

```bash
$ vendor/bin/greenlight run

Greenlight dev-main | PHP 8.4.14 | config: greenlight.php | workers: 11

3 tests, 3 passed, 3 expectations
Time: 0.110s
Workers: 1 spawned
```

While the run is in flight, an interactive terminal shows a live progress window with per-worker class activity; `--verbose` keeps a permanent line per completed class. In CI, the plain reporter emits deterministic append-only output with one line per test:

```
PASS App\Tests\PriceTest::multipliesLineTotals[two units] (0.001s)
PASS App\Tests\PriceTest::multipliesLineTotals[three small units] (0.000s)
PASS App\Tests\PriceTest::rejectsNegativeQuantities (0.000s)
```

Reporters are repeatable, so a run can emit terminal output, JUnit, and JSONL from the same execution: `--reporter=plain --reporter=junit --reporter=jsonl`.

## Parallel execution

Parallel execution is the default path, not an opt-in mode.

Tests run across a worker pool sized to the machine. Workers pull work on demand, which keeps the pool busy when one class is slower than the rest. Sequential execution is available with `--workers=1` and uses the same execution path, which makes debugger sessions straightforward.

Worker recycling keeps long suites healthy. A worker can be recycled after a test count threshold or a memory ceiling. CI gates a 10,000-test single-worker run at under 1 MiB of memory drift, and `--detect-leaks` names any test whose instance survives collection.

## Isolation

Some tests need a clean process. Greenlight runs tests marked `#[Isolated]` in a fresh process without forcing the entire suite into the slowest mode.

Use isolation when a test mutates global state, touches process-wide configuration, exercises shutdown behaviour, or loads code that cannot be safely unloaded. The rest of the suite keeps running in the normal worker pool.

A handful of isolated tests does not force a large suite to give up its speed.

## Fast, with published trade-offs

On [generated benchmark suites](docs/benchmarks.md), Greenlight's best configuration beats PHPUnit's best by 2.5x to 7x. Most of the margin comes from lower runner overhead per test, not only from parallel execution.

The benchmark documentation also includes the cases where parallelism loses. On trivial test bodies, worker spawn can cost more than the work being performed, so one worker wins.

That case is rarer than it sounds, because real suites spend their time on database calls, filesystem setup, container bootstrapping, HTTP clients, and application code, and that is exactly the kind of work a pool overlaps well.

Reproduce the numbers with:

```bash
php tools/benchmark.php --with-phpunit
```

CI smoke-runs the benchmark harness so the tooling keeps working; the published numbers come from full local runs.

## Strict test doubles

Greenlight's test doubles are designed to catch accidental gaps in verification.

Tests receive a per-test `Doubles` service through constructor injection and create doubles with `mock()`, `stub()`, and `spy()`.

Mocks answer only the interactions the test planned, stubs satisfy a type and error on anything unexpected, and spies record void-returning calls. Every double is verified when its test ends, and an unmet plan is reported like an assertion failure.

Plans stay explicit but expressive: answers come from `andReturns()`, `andReturnsSequence()`, `andReturnsUsing()`, or `andThrows()`; argument constraints come from `Argument::any()`, `Argument::type()`, `Argument::predicate()`, and `Argument::equals()`; and `captureArgument()` hands back a captor for asserting on what the subject actually passed.

The runner also flags passed tests that verified nothing as risky. `--fail-on-risky` upgrades risky tests to failures. `#[NoExpectations]` records the deliberate cases where a test legitimately asserts nothing.

## Writing tests

Greenlight includes attributes for the full test lifecycle:

* `#[Test]`
* `#[Before]`
* `#[After]`
* `#[Group]`
* `#[Skip]`
* `#[SkipUnless]`
* `#[Retry]`
* `#[Timeout]`
* `#[Isolated]`

Data-driven tests are expanded at plan time through provider methods and inline rows:

* `#[DataSet]`
* `#[DataRow]`

Named data keys appear in every report, which makes failures easier to identify.

Expectations use a fluent typed chain:

```php
Expect::that($value)->toBe($expected);
Expect::that($items)->toHaveCount(3);
Expect::that($callback)->toThrow(RuntimeException::class);
Expect::that($result)->not()->toBeNull();
```

Failed expectations throw immediately with a rendered diff.

Harness services can be scoped per test, class, suite, or run. Expensive fixtures are built lazily and disposed in reverse order.

## Running tests

Greenlight includes the controls needed for local development and CI:

* `--filter` for name patterns.
* `--group` for tagged subsets.
* `--exclude-group`, `--exclude-class`, `--exclude-method`, and `--exclude-path` to carve tests out of a run; exclusions always win.
* `--list-tests`, `--list-groups`, and `--list-suites` to print the current selection without running it.
* `--failed` to re-run the previous run's failures.
* `--repeat=N` to run the plan N times, and `--repeat-until-failure` to hunt flakes until one fails.
* `--bail[=n]` to stop after the first (or nth) failure.
* `--shard=n/m` to split a suite across CI machines without coordination.
* `--seed=N` to reproduce randomized order exactly.
* `--workers=N` to control parallelism; `--workers=1` runs sequentially for debugging.
* `--reporter=<name>` to select output, repeatable to emit several formats at once.
* `--watch` for debounced re-runs with failed-first ordering.
* `--config=<path>` to run against a config file other than `./greenlight.php`.
* `--dry-run` to print the resolved configuration without executing.

`--no-ansi` disables colours and the live progress window, and `--verbose` prints a permanent line per completed class. The `list-tests` command prints every discovered test id, one per line, for tooling and shard debugging.

Per-test output capture keeps stdout and PHP diagnostics attached to the result that produced them.

## Profiling

`--profile` appends runner performance information after the summary, including worker utilisation, boot latency, and the slowest classes.

`profile:report` can reproduce the profiling block offline from a saved artifact. That makes it easier to compare runs without keeping the original process output around.

## CI and reporting

Greenlight has one event stream and multiple reporters:

* `tty`
* `plain`
* `junit`
* `jsonl`
* `github`
* `teamcity`

The TTY reporter is meant for local runs, and plain output suits CI logs because it is append-only. JUnit feeds dashboards, JSONL feeds custom tooling, and the GitHub and TeamCity reporters add inline annotations where the CI system supports them.

CI gates include:

* `--fail-on-deprecation`
* `--fail-on-notice`
* `ignoreDeprecationsMatching()` to exempt known dependency deprecations
* coverage through pcov or Xdebug
* `coverage:diff` for regression gating

Coverage export formats:

* json
* lcov
* clover
* cobertura
* html

Exit codes are deterministic: 0 for success, 1 for any failure, 64 for usage errors. A run that discovers zero tests exits 1 as a misconfiguration.

## Extending Greenlight

Plugins receive live runtime context:

* the actual test instance
* test metadata
* harness services

Extension points include per-test lifecycle subscribers, run-level event subscribers, retry deciders, harness providers, service resolvers, custom expectation matchers, and custom reporters.

Custom expectation matchers stay statically checked. The bundled PHPStan extension reads the Greenlight config and fails analysis on matcher name typos or wrong arguments, and it validates `#[DataSet]` providers and `#[DataRow]` rows against the test method's signature. The `ide-helper` command generates autocomplete support with real signatures. See [static analysis with PHPStan](docs/phpstan.md).

The `completion` command prints shell completion scripts for:

* bash
* zsh
* fish

## Requirements

Greenlight requires PHP 8.4 or later.

Lazy objects, property hooks, and asymmetric visibility are load-bearing parts of the design, so older runtimes are not supported.

The parallel runner uses core stream sockets and `proc_open`. It does not require an extension.

Coverage requires one of:

* `ext-pcov`
* Xdebug

## Documentation

* [Getting started](docs/getting-started.md)
* [Configuration reference](docs/configuration.md)
* [Attribute reference](docs/attributes.md)
* [Writing plugins](docs/plugins.md)
* [Static analysis with PHPStan](docs/phpstan.md)
* [Testing Symfony applications](docs/symfony.md)
* [Migrating from PHPUnit](docs/migrating-from-phpunit.md)
* [Benchmarks](docs/benchmarks.md)
* [JSONL reporter schema](docs/architecture/jsonl.md)
* [Coverage JSON schema](docs/architecture/coverage-json.md)
* [Code conventions](docs/architecture/conventions.md)
* [Contributing guide](CONTRIBUTING.md)

## License

MIT. See [LICENSE](LICENSE).
