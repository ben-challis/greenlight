# Start with Greenlight

Greenlight is an attribute-based test framework for PHP 8.4 and later. It runs
tests in parallel by default.

This guide starts with an empty project and creates a successful test run.

## Requirements and installation

Greenlight requires PHP 8.4 or later. It does not require a PHP extension.

The parallel runner uses core stream sockets and `proc_open`. Coverage requires
`ext-pcov` or Xdebug in coverage mode.

If PHP disables a process or stream function that the parallel runner requires,
Greenlight uses an in-process sequential run.

Install Greenlight as a development dependency:

```sh
composer require --dev greenlight/greenlight
```

## Create the configuration file

Greenlight reads `greenlight.php` from the project root. This file returns a
typed builder.

Create this minimum configuration:

<!-- php-example {"example":"getting-started","file":"greenlight.php","mode":"file","tools":["phpstan","rector"]} -->
```php
<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->workers(count: 'auto');
```

`paths()` specifies the directories that Greenlight scans for tests.
`workers('auto')` calculates the worker count from the CPU count.

These values are defaults. Thus, this configuration has the same result:

<!-- php-example {"example":"getting-started-example-02","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
return GreenlightConfig::create();
```

The longer form can make a new project easier to understand. See the
[configuration reference](configuration.md) for the complete builder API.

## Create the first test

Tests are PHP classes. Add `#[Test]` to each test method.

Greenlight does not require a `TestCase` base class or a test method name
pattern. Start each expectation with `Expect::that()`.

Constructor injection supplies stateful test services when a test requests
them.

Create this small class:

<!-- php-example {"example":"getting-started","file":"src/Greeter.php","mode":"file","tools":["phpstan","rector"]} -->
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

Save the file as `src/Greeter.php`.

Create a test for both results of the public method:

<!-- php-example {"example":"getting-started","file":"tests/GreeterTest.php","mode":"file","tools":["phpstan","rector"]} -->
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

Save the file as `tests/GreeterTest.php`.

Map `App` to `src/` in Composer. Map `App\Tests` to `tests/`.

`Expect::that()` starts a matcher chain for a value. A failed matcher throws
immediately and includes a clear difference when applicable.

The [expectations reference](expectations.md) describes each matcher, negation,
polls, exception checks, and explicit failures.

## Run tests

Run the suite:

```sh
vendor/bin/greenlight run
```

`run` is the default command. This command has the same result:

```sh
vendor/bin/greenlight
```

Use these commands for common tasks:

* `vendor/bin/greenlight list-tests` prints each discovered test ID.
* `vendor/bin/greenlight run --dry-run` prints the resolved run-settings summary.
* `vendor/bin/greenlight run --workers=1` uses one in-process worker.
* `vendor/bin/greenlight run --group=slow` selects tests with `#[Group('slow')]`.
* `vendor/bin/greenlight run --exclude-group=slow` excludes that group.
* `vendor/bin/greenlight run --list-tests` prints the selected tests.
* `vendor/bin/greenlight run --bail` stops after the first failure.

The `--exclude-class`, `--exclude-method`, and `--exclude-path` flags also
exclude tests. Exclusion rules take priority over inclusion rules.

The `--list-groups` and `--list-suites` flags print the discovered groups and
configured suites.

Repeat one plan to find an intermittent failure:

```sh
vendor/bin/greenlight run --filter=CheckoutTest --repeat=20
vendor/bin/greenlight run --filter=CheckoutTest --repeat-until-failure
```

Each iteration reports its number. The summary identifies each failed
iteration.

The command returns a nonzero exit code if an iteration fails.
`--repeat-until-failure` stops after the first failed iteration.

Without `--repeat=N`, this mode stops after 100 iterations. Add `--repeat=N`
to specify a different limit.

Repeat modes do not support JUnit output or enabled coverage. Run a separate
command for each required report.

## Read the output

Greenlight uses the `tty` reporter on an interactive terminal. This reporter
shows live progress with ANSI color and prints failure differences immediately.

Greenlight uses the `plain` reporter when standard output is not a TTY. This
reporter prints one line for each event and does not print escape codes.

Select a reporter with `--reporter`:

```sh
vendor/bin/greenlight run --reporter=plain
vendor/bin/greenlight run --reporter=junit
```

Repeat the flag to select more than one reporter:

```sh
vendor/bin/greenlight run --reporter=tty --reporter=junit
```

Add a file to keep a reporter separate from standard output:

```sh
vendor/bin/greenlight run --reporter=tty --reporter=junit=reports/junit.xml
```

Greenlight creates missing parent directories. It replaces an existing file.

Plugins can register custom reporter factories. See
[ReporterProvider](plugins.md#reporterprovider).

Tests can retain diagnostic data without output to standard output. Inject
`Greenlight\Artifact\Attachments`.

Call `value()`, `text()`, `bytes()`, or `file()` to add an attachment.
Greenlight prints retained paths and stores files below
`build/greenlight-artifacts` by default.

See [test attachments](attachments.md) for more information.

## Use watch mode

Start watch mode:

```sh
vendor/bin/greenlight run --watch
```

Watch mode runs all selected tests at startup and after each file change. It
watches effective test paths and coverage include paths.

Classes that failed in the previous watch iteration run first.

Press Enter to rerun all selected tests. Press `q` to stop watch mode with exit
code 0, regardless of the last iteration result.

Watch mode does not print coverage totals or write coverage exports.

Watch mode combines rapid save events. The default delay is 200 ms.

Use the `watch()` configuration builder to change this delay.

Add `--watch-impacted` to select previous failures and tests affected by a
change. This mode requires a coverage include path and an available coverage
driver.

Impacted watch uses exact test IDs from per-test coverage. It runs the complete
selected plan when the map cannot give a reliable selection.

## Configure workers

Tests run in parallel worker processes by default.

`--workers=auto` uses one worker for each CPU core. This value is the default.

`--workers=4` specifies four workers. `--workers=1` uses one in-process runner.
The last mode is usually the simplest choice for debug work.

A worker remains active until it has no more tests or the worker fails.
This behavior makes memory growth and state leaks visible in the suite.

Parallel suites need separate resources for workers or a limit for one shared
dependency. Greenlight supports both methods.

Use a channel when each worker has a separate external resource. The channel
number is from 1 through the worker count.

Each worker receives its channel through `Greenlight\Test\TestChannel` and
the `GREENLIGHT_CHANNEL` environment variable.

A plugin can also provision real infrastructure in the orchestrator and expose
typed connection data through `Greenlight\IntegrationFixture\IntegrationResources`.
That keeps setup and teardown active when a worker crashes.
See [Writing plugins](plugins.md#integrationfixtureprovider).

<!-- php-example {"example":"getting-started-example-05","file":"snippet.php","mode":"file","tools":["rector"]} -->
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

Concurrent tests never share a channel. Thus, databases such as `app_test_1`
and `app_test_2` do not conflict.

Use `#[RequiresResource]` when several workers use one dependency with limited
capacity:

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
```php
#[RequiresResource('payments-sandbox')]
final class PaymentGatewayTest { ... }
```

<!-- php-example {"example":"getting-started-example-07","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
return GreenlightConfig::create()
    ->workers(8)
    ->resourceLimit('payments-sandbox', 2);
```

Other workers can run tests that do not require `payments-sandbox`. Without a
configured limit, only one class can use the resource.

A resource limit controls capacity. It does not select a sandbox, database, or
account for a test.

Use a channel when each worker has one resource instance. Use an
application-owned allocator for fewer resource instances.

A Greenlight resource limit can restrict the tests that enter that allocator.

A test can use both methods. Its channel can select a database while
`#[RequiresResource]` controls access to a shared service.

Resource limits apply to one Greenlight run. Other worktrees and CI shards have
separate counts.

Use external coordination when different processes share the dependency. See
the [configuration reference](configuration.md) for all resource limit rules.

## Use built-in sandboxes

The harness supplies per-test sandboxes from `Greenlight\Sandbox`. Constructor
injection supplies each sandbox.

Greenlight disposes each sandbox after its test. Thus, temporary state does not
leak to other tests or workers.

* `TemporaryDirectory` creates a unique temporary directory on first use. It removes
  the directory after the test.
* `EnvironmentVariables` changes environment variables in `getenv`, `$_ENV`, and
  `$_SERVER`. It restores the original values after the test.
* `Autoloaders` registers PHP autoloaders. It unregisters them in reverse
  registration order after the test.
* `StreamWrappers` registers PHP stream wrappers. It unregisters them in
  reverse registration order after the test.

<!-- php-example {"example":"getting-started-example-08","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;

final class ExporterTest
{
    public function __construct(
        private readonly TemporaryDirectory $tmp,
        private readonly EnvironmentVariables $env,
    ) {}

    #[Test]
    public function writesTheExportFile(): void
    {
        $this->env->set('EXPORT_DIR', $this->tmp->path());

        new Exporter()->run();

        Expect::that(\file_exists($this->tmp->path() . '/export.csv'))->toBeTrue();
    }
}
```

Each test receives separate sandbox instances. Each `TemporaryDirectory`
instance has a unique directory.

## Defer test cleanup

Ask for `Greenlight\Test\Cleanup` through constructor injection. Call
`defer()` immediately after the test acquires a resource.

<!-- php-example {"example":"getting-started-example-09","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Attribute\Test;
use Greenlight\Test\Cleanup;

final readonly class ServerTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function acceptsAConnection(): void
    {
        $server = TestServer::start();
        $this->cleanup->defer(static fn() => $server->stop());

        // Test the server.
    }
}
```

Greenlight runs cleanup callbacks once in reverse registration order. It runs
them after `After` hooks and before per-test sandbox disposal.

A callback failure does not prevent the remaining callbacks. A cleanup failure
errors a passed or skipped test. An earlier test failure or error remains
primary.

## Exit codes

Greenlight uses three exit codes:

* `0` means that the run succeeded.
* `1` means that the run failed or found no tests.
* `64` means that the command has a usage error.

Exit code `1` includes test failures, test errors, invalid configuration,
discovery errors, coverage export errors, and detected leaks.

Greenlight treats a run without tests as a configuration problem.
