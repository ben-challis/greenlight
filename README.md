# Greenlight

**Status: pre-release. Feature-complete and self-hosted; no version tag yet and not published to Packagist.**

Greenlight is a testing framework for PHP 8.4 and later. Tests are plain typed classes: attributes mark the tests, assertions are one static call away, constructor injection delivers stateful services like fixtures and doubles, and there is no base class, so PHPStan and your IDE understand your test code with no framework-specific plugins. Every run executes across a parallel worker pool by default, memory stays flat however large the suite grows, and the framework itself has zero runtime dependencies, so it never version-conflicts with the code under test.

Greenlight tests itself: this repository's suite runs under `bin/greenlight run` across an auto-sized worker pool.

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

No `TestCase` to extend, no method-name conventions, no closure DSL. `Expect::that()` anchors a fully typed matcher chain, and a failed matcher throws immediately with a typed, rendered diff. When a test needs a stateful service, such as the built-in test doubles or a fixture a plugin provides, it declares a constructor parameter and the harness injects it. Configuration is one PHP file at the project root, checked by PHPStan like the rest of your code:

```php
<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->workers(count: 'auto');
```

Then run it:

```
$ vendor/bin/greenlight run

  ✓ PriceTest multipliesLineTotals [two units]
  ✓ PriceTest multipliesLineTotals [three small units]
  ✓ PriceTest rejectsNegativeQuantities

  3 passed in 0.1s
```

On an interactive terminal you get a live, parallel-aware display; in CI the plain reporter prints one line per event, and `--reporter=junit` (repeatable, so you can have both) feeds your dashboard.

## Parallel by default

Tests run across a pool of worker processes sized to your CPU count, with workers pulling work on demand so none of them idles behind another's slow class. Workers recycle by test count or memory ceiling, which keeps memory flat over arbitrary suite sizes: CI gates a 10,000-test single-worker run at under 1 MiB of drift, and `--detect-leaks` names any test whose instance survives collection. Sequential execution is just `--workers=1`, the same code path and the easiest mode to attach a debugger to.

## Fast, with the losses published

On [generated benchmark suites](docs/benchmarks.md), Greenlight's best configuration beats PHPUnit's best by 2.5x to 7x, and most of the margin is engine overhead per test rather than parallelism. The benchmarks document also publishes where parallelism costs more than it saves: on trivial test bodies, worker spawn outweighs the work, so one worker wins. Real suites do real work, which is where the pool pays off. The numbers are reproducible with `php tools/benchmark.php --with-phpunit`, and CI runs the harness so it cannot rot.

## Strict where guessing hides bugs

Test doubles never guess: mocks answer only what you planned, stubs exist to satisfy a type and error on any interaction, spies record void-returning calls. Every double is verified when its test ends, and an unmet plan reads like an assertion failure. On the runner side, a passed test that verified nothing is flagged as risky, `--fail-on-risky` upgrades it to a failure, and `#[NoExpectations]` is the explicit opt-out for tests that legitimately assert nothing by design.

## What you get

Writing tests:

- Attributes for the full lifecycle: `#[Test]`, `#[Before]`, `#[After]`, `#[Group]`, `#[Skip]`, `#[SkipUnless]`, `#[Retry]`, `#[Timeout]`, `#[Isolated]`.
- Data-driven tests through `#[DataSet]` provider methods and inline `#[DataRow]` rows, expanded at plan time with named keys in every report.
- A fluent `Expect::that()` chain with a `not()` modifier and typed diff rendering.
- Scoped harness services (per test, class, suite, or run) for expensive fixtures, built lazily and disposed in reverse order.

Running them:

- `--filter` patterns, `--group` tags, `--failed` to re-run the previous run's failures, and `--shard=n/m` to split a suite across CI machines with no coordination.
- Watch mode with debounced re-runs and failed-first ordering.
- Deterministic seeded ordering: `--seed=N` reproduces any randomized run exactly.
- `--profile` appends worker utilisation, boot latency, and the slowest classes after the summary; `profile:report` reproduces the block offline from a saved artifact.
- Per-test output capture, so stdout and PHP diagnostics land on the result instead of corrupting the report stream.

Gating CI:

- `--fail-on-deprecation` and `--fail-on-notice` fail passed tests on captured diagnostics, with a config allow-list for dependency noise.
- Coverage via pcov or Xdebug with five export formats (json, lcov, clover, cobertura, html) and a `coverage:diff` regression gate.
- Six reporters over one event stream: tty, plain, junit, jsonl, github, teamcity.
- Three exit codes with honest semantics: a run that discovers zero tests fails as a misconfiguration.

Extending it:

- Plugins receive live runtime context: the actual test instance, its metadata, and its harness services. Lifecycle subscribers, retry deciders, harness providers, and custom reporters all hang off small capability interfaces.
- Custom expectation matchers stay statically checked: the bundled PHPStan extension reads your config and fails analysis on name typos and wrong arguments, and the `ide-helper` command makes them autocomplete with real signatures.
- A `completion` command prints shell completion scripts for bash, zsh, and fish.

## Requirements

PHP 8.4 or later, nothing else. Lazy objects, property hooks, and asymmetric visibility are load-bearing in the design, so older runtimes are not supported. The parallel runner uses core stream sockets and `proc_open` and needs no extension; `ext-pcov` or Xdebug enable coverage.

## Documentation

- [Getting started](docs/getting-started.md)
- [Configuration reference](docs/configuration.md)
- [Attribute reference](docs/attributes.md)
- [Writing plugins](docs/plugins.md)
- [Testing Symfony applications](docs/symfony.md)
- [Migrating from PHPUnit](docs/migrating-from-phpunit.md)
- [Benchmarks](docs/benchmarks.md)
- [Product Requirements Document](docs/PRD.md) describes the full design.
- [Build plan](docs/plan/README.md) and [RFCs](docs/rfcs/) record how and why it was built this way.
- [Contributing guide](CONTRIBUTING.md) covers the rules for changes.

## License

MIT. See [LICENSE](LICENSE).
