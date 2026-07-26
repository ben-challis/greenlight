# Getting started

Greenlight is an attribute-based testing framework for PHP 8.4 and later. It
runs tests in parallel by default.

This page takes an empty project to a passing test run.

## Requirements and installation

Greenlight requires PHP 8.4 or newer.

It has no required PHP extensions. The parallel runner uses core stream sockets
and `proc_open`. Code coverage needs `ext-pcov`, or Xdebug running in coverage
mode.

If `proc_open` is disabled, Greenlight falls back to an in-process sequential
run.

Install it as a dev dependency:

```sh
composer require --dev greenlight/greenlight
```

## The config file

Greenlight reads `greenlight.php` from the project root. The file returns a
typed builder. There is no XML, YAML, or JSON config format.

A minimal config:

```php
<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->workers(count: 'auto');
```

`paths()` lists the directories to scan for tests. `workers('auto')` sizes the
worker pool from the CPU count.

Both are defaults, so this would behave the same way:

```php
return GreenlightConfig::create();
```

The longer form is often clearer in a new project. The full builder API is
documented in [configuration](configuration.md).

## Your first test

Tests are ordinary classes. Test methods are marked with `#[Test]`.

There is no `TestCase` base class and no test-method naming convention.
Assertions start from `Expect::that()`. Stateful test services, such as doubles,
are provided by constructor injection when a test asks for them.

Start with a small class to test:

```php
<?php

declare(strict_types=1);

namespace App;

final class Greeter
{
    public function greet(string $name): string
    {
        $name = \trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Name cannot be empty.');
        }

        return \sprintf('Hello, %s!', $name);
    }
}
```

Save it as `src/Greeter.php`. Its test exercises both outcomes through the
public method:

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use App\Greeter;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class GreeterTest
{
    #[Test]
    public function greetsByName(): void
    {
        $greeter = new Greeter();

        Expect::that($greeter->greet('Ada'))->toBe('Hello, Ada!');
    }

    #[Test]
    public function rejectsEmptyNames(): void
    {
        $greeter = new Greeter();

        Expect::that(
            static fn (): string => $greeter->greet(''),
        )->toThrow(\InvalidArgumentException::class, matching: '/empty/');
    }
}
```

Save this as `tests/GreeterTest.php`. Make sure Composer maps `App` to `src/`
and `App\Tests` to `tests/`, then the test will run as written.

`Expect::that()` starts a matcher chain for a value. A failed matcher throws
immediately and includes a rendered diff where applicable.

The [expectations reference](expectations.md) covers every matcher, negation and
chaining, asynchronous polling, exception checks, and explicit failures.

## Running tests

```sh
vendor/bin/greenlight run
```

`run` is the default command, so this is equivalent:

```sh
vendor/bin/greenlight
```

Some useful commands:

* `vendor/bin/greenlight list-tests` prints every discovered test id.
* `vendor/bin/greenlight run --dry-run` prints the resolved plan without
  executing it.
* `vendor/bin/greenlight run --workers=1` uses one in-process worker.
* `vendor/bin/greenlight run --group=slow` runs only tests tagged
  `#[Group('slow')]`.
* `vendor/bin/greenlight run --exclude-group=slow` runs everything except that
  group.
* `vendor/bin/greenlight run --list-tests` prints the selection instead of
  running it.
* `vendor/bin/greenlight run --bail` stops after the first failure.

`--exclude-class`, `--exclude-method`, and `--exclude-path` carve tests out the
same way, and exclusions always win over includes. `--list-groups` and
`--list-suites` print the discovered groups and the configured suites.

To hunt a flaky test, repeat the same plan:

```sh
vendor/bin/greenlight run --filter=CheckoutTest --repeat=20
vendor/bin/greenlight run --filter=CheckoutTest --repeat-until-failure
```

Each iteration reports its number, the summary names the iterations that
failed, and the exit code is non-zero if any iteration failed.
`--repeat-until-failure` stops at the first failing iteration; on its own it
gives up after 100 iterations, or combine it with `--repeat=N` to set the
limit.

## Reading the output

On an interactive terminal, Greenlight uses the `tty` reporter. It shows live
progress with ANSI colour and prints failure diffs as they happen.

When stdout is not a TTY, such as in CI or a pipe, Greenlight uses the `plain`
reporter. It prints one line per event and no escape codes.

Use `--reporter` to choose a format explicitly:

```sh
vendor/bin/greenlight run --reporter=plain
vendor/bin/greenlight run --reporter=junit
```

The flag is repeatable, so you can emit more than one format:

```sh
vendor/bin/greenlight run --reporter=tty --reporter=junit
```

Tests can retain diagnostic data without printing it to stdout. Inject
`Greenlight\Core\Artifact\Attachments`, then call `value()`, `text()`, `bytes()`,
or `file()`. Greenlight prints the retained paths and stores the files below
`build/greenlight-artifacts` by default. See
[test attachments](attachments.md).

## Watch mode

```sh
vendor/bin/greenlight run --watch
```

Watch mode reruns affected tests when files under the configured paths change.
Classes that failed in the previous run are prioritised.

While watching, Enter reruns everything and `q` quits.

Save bursts are debounced. The default debounce is 200 ms and can be changed
with the `watch()` config builder.

## Workers

Tests run in parallel worker processes by default.

`--workers=auto` is the default and uses one worker per CPU core.
`--workers=4` sets an explicit count.
`--workers=1` runs everything in a single in-process runner, which is usually the
simplest mode for debugging.

Workers are recycled when they grow past 256M, so long runs do not keep growing
memory indefinitely. Suites that accumulate non-memory state can also recycle
workers after a fixed number of tests. Both thresholds are configured with
`workers()` in `greenlight.php`.

Parallel suites usually need either a separate resource for each worker or a
concurrency limit around one shared dependency. Greenlight supports both.

Use the channel number when each worker can have its own external resource. The
number stays between 1 and the worker count.

The channel is available as `Greenlight\Core\Test\TestChannel` and is also
exported to each worker as the `GREENLIGHT_CHANNEL` environment variable.

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

Two tests running at the same time never share a channel, so databases such as
`app_test_1` and `app_test_2` do not race each other.

Use `#[RequiresResource]` when workers must use the same dependency but it has a
lower safe concurrency than the worker pool:

```php
#[RequiresResource('payments-sandbox')]
final class PaymentGatewayTest { ... }
```

```php
return GreenlightConfig::create()
    ->workers(8)
    ->resourceLimit('payments-sandbox', 2);
```

Tests that do not need `payments-sandbox` can keep running on other workers. If
no limit is configured, only one class that requires the resource runs at a
time.

A resource limit is a capacity gate. It does not choose which sandbox, database,
or account a test should use. Use a channel when one instance per worker is
available. A smaller set of distinct instances needs an application-owned
allocator. A Greenlight limit can keep excess tests from blocking inside that
allocator.

A test can use both mechanisms. Its channel can select a database while
`#[RequiresResource('payments-sandbox')]` limits access to a shared service.

Limits apply to one Greenlight run. Concurrent runs from another worktree or CI
shard keep their own counts, so use external coordination when the dependency is
shared across processes.

See [configuration](configuration.md) for the full channel and resource limit
rules.

## Built-in fixtures

The harness ships a small set of per-test fixtures in `Greenlight\Fixture`.
Like every harness service, they arrive by constructor injection and are
disposed automatically after each test, so cleanup never leaks between tests
or workers.

* `TempDirectory` creates a unique temporary directory on first use and removes
  it, recursively, when the test finishes.
* `EnvironmentSandbox` sets and unsets environment variables (`getenv`, `$_ENV`,
  and `$_SERVER` together) and restores the original values afterwards.

```php
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Fixture\TempDirectory;

final class ExporterTest
{
    public function __construct(
        private readonly TempDirectory $tmp,
        private readonly EnvironmentSandbox $env,
    ) {}

    #[Test]
    public function writesTheExportFile(): void
    {
        $this->env->set('EXPORT_DIR', $this->tmp->path());

        (new Exporter())->run();

        Expect::that(\file_exists($this->tmp->path() . '/export.csv'))->toBeTrue();
    }
}
```

Each test gets its own instances, and directories are unique per instance, so
the fixtures are safe under parallel workers.

## Exit codes

Greenlight uses three exit codes:

* `0`: the run succeeded.
* `1`: the run failed. This includes failing or erroring tests, invalid
  configuration, discovery errors, coverage export errors, detected leaks, and a
  run that discovered no tests. An empty run is treated as a configuration
  problem.
* `64`: usage error, such as an unknown command, unknown flag, or malformed
  option value.
