# Getting started

Greenlight is an attribute-driven, parallel-first testing framework for PHP 8.4 and later. This page takes you from an empty project to a passing test run. For the reasoning behind the design, see [the PRD](PRD.md).

## Requirements and installation

You need PHP 8.4 or newer. Greenlight has no required extensions: the parallel runner is built on core stream sockets and `proc_open`, and `ext-pcov` (or Xdebug in coverage mode) enables coverage collection. Hosts that disable `proc_open` get an in-process sequential run automatically.

Greenlight is not yet published to Packagist, so the following is forward-looking. Once released, install it as a dev dependency:

```sh
composer require --dev greenlight/greenlight
```

## The config file

Greenlight reads a single file, `greenlight.php`, at your project root. It returns a fluent, fully typed builder. There is no XML, YAML, or JSON alternative. A minimal config:

```php
<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->workers(count: 'auto');
```

`paths()` lists the directories Greenlight scans for tests. `workers('auto')` sizes the worker pool to your CPU count. Both are the defaults, so `GreenlightConfig::create()` alone would behave identically; spelling them out keeps the file honest. The full builder surface is documented in [configuration](configuration.md).

## Your first test

Tests are final classes. Test methods are marked with `#[Test]`; there is no `TestCase` base class and no method-name convention. Assertions start from the static `Expect::that()`; stateful services such as test doubles arrive by constructor injection when a test asks for them:

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class GreetingTest
{
    #[Test]
    public function greetsByName(): void
    {
        $greeting = \sprintf('Hello, %s!', 'Ada');

        Expect::that($greeting)
            ->toBe('Hello, Ada!')
            ->and(\strlen($greeting))->toBeGreaterThan(5);
    }

    #[Test]
    public function rejectsEmptyNames(): void
    {
        Expect::that(static function (): void {
            throw new \InvalidArgumentException('Name cannot be empty.');
        })->toThrow(\InvalidArgumentException::class, matching: '/empty/');
    }
}
```

Save this as `tests/GreetingTest.php`, make sure your Composer autoloader maps the `App\Tests` namespace to `tests/`, and it will run as-is. `Expect::that()` anchors a matcher chain on a value; a failed matcher throws immediately with a rendered diff. The full matcher list is in [configuration](configuration.md)'s sibling pages and in the `Greenlight\Expect\Expectation` class itself.

## Running tests

```sh
vendor/bin/greenlight run
```

`run` is the default command, so plain `vendor/bin/greenlight` does the same. Other useful invocations:

```sh
vendor/bin/greenlight list-tests          # print every discovered test id
vendor/bin/greenlight run --dry-run       # print the resolved plan without executing
vendor/bin/greenlight run --workers=1     # single worker, runs in-process
vendor/bin/greenlight run --group=slow    # only tests tagged #[Group('slow')]
vendor/bin/greenlight run --bail          # stop after the first failure
```

## Reading the output

On an interactive terminal Greenlight uses the `tty` reporter: a live, ANSI-colored view of progress with failure diffs as they happen. When stdout is not a TTY (CI, pipes) it falls back to the `plain` reporter, which prints one line per event with no escape codes. You can force a format with `--reporter=plain`, or emit machine formats such as `--reporter=junit` for CI; the flag is repeatable, so `--reporter=tty --reporter=junit` gives you both.

## Watch mode

```sh
vendor/bin/greenlight run --watch
```

Watch mode re-runs affected tests when files under your configured paths change. Failed classes from the previous run are prioritised. Two keys are bound while watching: Enter re-runs everything, and q quits. Save bursts are debounced (200 ms by default, tunable via the `watch()` builder).

## Workers

Tests run in parallel worker processes by default. `--workers=auto` (the default) uses one worker per CPU core; `--workers=4` pins the count; `--workers=1` runs everything in a single in-process runner, which is the easiest mode to attach a debugger to. Workers are recycled when they grow past 256M, so long runs keep flat memory; recycling after a fixed test count is available through `workers()` for suites that accumulate non-memory state. Both thresholds are configurable through `workers()` in the config file.

When parallel tests share an external resource such as a database, give each worker its own copy using the channel: a stable slot from 1 to the worker count, injectable as `Greenlight\Core\Test\TestChannel` and exported to each worker as the `GREENLIGHT_CHANNEL` environment variable.

```php
final class OrderRepositoryTest
{
    public function __construct(
        private readonly TestChannel $channel,
    ) {}

    #[Test]
    public function persistsAnOrder(): void
    {
        $pdo = new \PDO('mysql:host=127.0.0.1;dbname=app_test_' . $this->channel->number, 'app', 'secret');
        // ...
    }
}
```

Two tests running at the same time never share a channel, so `app_test_1` and `app_test_2` never race. See [configuration](configuration.md) for the full channel semantics.

## Exit codes

Greenlight uses three exit codes:

- 0: the run succeeded.
- 1: the run failed. This covers failing or erroring tests, invalid configuration, discovery errors, coverage export problems, detected leaks, and a run that discovered zero tests. An empty run is treated as a misconfiguration, not a pass.
- 64: usage error, such as an unknown flag, an unknown command, or a malformed option value.
