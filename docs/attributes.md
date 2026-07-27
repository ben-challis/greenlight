# Attributes

Declare test metadata with attributes from the `Greenlight\Attribute`
namespace.

There are no method-name conventions and no annotations. Attributes on a class
apply to every test method in that class.

## Test

Target: method.

Parameters:

```php
bool $capture = true
```

Marks a public method as a test.

Greenlight enables output capture by default. It records output from PHP's
output buffer, such as `echo` and `print`. It also records notices, warnings,
and deprecations from the test. Reporters can associate this output with its
test.

Direct writes to the `STDOUT` and `STDERR` stream resources bypass PHP's output
buffer. Greenlight does not capture these writes. They can also interfere with
terminal output. Do not use these resources for test diagnostics. Use test
attachments to retain diagnostic content. See [test
attachments](attachments.md).

Set `capture: false` only when a test needs to control PHP's output buffer or
error handler itself. With capture disabled, Greenlight does not record output
or diagnostics for that test.

```php
#[Test]
public function totalsAreRounded(): void { ... }

#[Test(capture: false)]
public function managesItsOwnOutputBuffer(): void { ... }
```

## Before

Target: method.

No parameters.

Marks a public method to run before each test in the class.

If a class has multiple before-hooks, Greenlight runs them in declaration
order.

A before-hook can throw `SkipTest` to skip the test. A throwable other than
`SkipTest` gives the test an error.

## After

Target: method.

No parameters.

Marks a public method to run after each test in the class. The method also runs
after a test failure or error.

If a class has multiple after-hooks, Greenlight runs them in reverse declaration
order. Thus, the before-hooks and after-hooks form a stack.

Greenlight calls each after-hook, even if an earlier hook throws. If the test
does not have a failure or error, the first throwable becomes its cause.

## DataSet

Target: method.

Parameters:

```php
string $provider
?string $method = null
```

With one argument, references a public static provider method on the test
class. With two arguments, the first argument is a provider class. The second
argument is its public static method:

```php
#[DataSet('currencies')]
```

or:

```php
#[DataSet(CurrencyDataSets::class, 'currencies')]
```

The provider must return an iterable of named data sets for the test method.

Providers run at discovery time before tests execute. Do not use I/O or global
state in a provider.

Each provider key names a data set and appears in test IDs and reports. Each
provider value is the argument list for one test invocation.

```php
#[Test]
#[DataSet('currencies')]
public function roundsPerCurrency(Currency $currency, string $expected): void { ... }

/** @return iterable<string, array{Currency, string}> */
public static function currencies(): iterable
{
    yield 'GBP rounds half-up' => [Currency::GBP, '10.01'];
    yield 'JPY has no minor unit' => [Currency::JPY, '10'];
}
```

Use the two-argument form to share a provider between test classes:

```php
final class CurrencyDataSets
{
    /** @return iterable<string, array{Currency, string}> */
    public static function currencies(): iterable
    {
        yield 'GBP rounds half-up' => [Currency::GBP, '10.01'];
    }
}

#[Test]
#[DataSet(CurrencyDataSets::class, 'currencies')]
public function roundsSharedCurrencyCases(Currency $currency, string $expected): void { ... }
```

The bundled PHPStan extension validates providers without their execution. Each
provider must exist, be public and static, and return an iterable of argument
arrays. PHPStan compares visible row shapes with the test method parameters.
The `array{...}` return type above is one visible row shape. The extension
applies the same check to `#[DataRow]` literals. See [static analysis with
PHPStan](phpstan.md).

## DataRow

Target: method.

Repeatable.

Parameters:

```php
array $arguments
?string $label = null
```

Adds one inline data set.

`$arguments` contains the test arguments in parameter order. The label becomes
the data-set key in test IDs and reports. Without a label, Greenlight uses
`#<position>` as the key for the inline row.

Inline rows can contain only values that PHP attributes can express. Examples
include scalars, arrays, and constants. Use a `#[DataSet]` provider for
calculated rows, ranges, or objects.

You can use `#[DataRow]` and `#[DataSet]` on the same method. They use one
data-set key space. Thus, duplicate keys cause a discovery error.

```php
#[Test]
#[DataRow([1, 2, 3], label: 'small')]
#[DataRow([10, 20, 30])]
public function addsUp(int $a, int $b, int $sum): void { ... }
```

## NoExpectations

Target: method.

No parameters.

Declares that the test intentionally verifies no expectations.

Use this for tests that pass and do not throw. Risky-test detection and
`--fail-on-risky` ignore tests marked with this attribute.

The attribute makes the intent explicit. Thus, Greenlight does not confuse this
test with a test that has a missing assertion.

An `eventually()` or `consistently()` matcher counts as one expectation.

## Group

Target: method or class.

Repeatable.

Parameters:

```php
string $name
```

Tags a test method, or every test in a class, with a group name.

Select groups at run time with `--group=<name>`. The flag is
repeatable. `list-tests` applies the same filter.

```php
#[Group('slow')]
#[Group('io')]
final class ImportTest { ... }
```

## Skip

Target: method or class.

Parameters:

```php
string $reason
```

Skips the test method, or every test in the class, unconditionally.

You must give a reason. The reason appears in the report.

Greenlight does not construct skipped tests.

## SkipUnless

Target: method or class.

Parameters:

```php
string $condition
mixed ...$arguments
```

`$condition` must be a class-string for `Greenlight\Core\Condition`.

Skips the test if the condition is false.

Greenlight passes the remaining attribute arguments to the condition
constructor. Arguments must be scalars or null because Greenlight sends them to
parallel workers. Another argument type causes a discovery error. The
constructor must only store the arguments. The `isSatisfied()` method must
evaluate the condition without side effects:

```php
interface Condition
{
    public function isSatisfied(): bool;
}
```

The worker evaluates the condition before it constructs the test class. If the
condition is false, Greenlight does not use constructor injection or harness
services.

If the condition throws, the test has an error. Greenlight does not skip it.

```php
#[Test]
#[SkipUnless(RedisIsRunning::class)]
public function storesSessionsInRedis(): void { ... }

#[Test]
#[SkipUnless(ExtensionLoaded::class, 'redis')]
public function usesTheRedisExtension(): void { ... }
```

### Built-in conditions

The `Greenlight\Condition` namespace ships conditions for the common
environment checks, so most `#[SkipUnless]` uses need no hand-written class:

* `ExtensionLoaded('redis')` checks for the extension.
* `ExtensionMissing('xdebug')` checks for the absence of the extension.
* `EnvironmentVariableSet('CI')` checks that `getenv()` returns a value.
* `EnvironmentVariableEquals('APP_ENV', 'test')` checks that the variable
  equals the value exactly.
* `OperatingSystemFamily('Linux')` compares the value with `PHP_OS_FAMILY`
  without regard to case.
* `PhpVersionAtLeast('8.5')` checks that `PHP_VERSION` is at least the given
  version.
* `PhpVersionLessThan('9.0')` checks that `PHP_VERSION` is below the given
  version.
* `FunctionAvailable('pcntl_fork')` checks that the function exists.
* `ClassAvailable(Redis::class)` checks that the class exists.

The skip reason names the condition and its arguments, for example
`Condition ExtensionLoaded("redis") is not satisfied.`

## Retry

Target: method or class.

Parameters:

```php
int $times
?string $onlyOn = null
```

Retries a failed test up to `$times` additional attempts.

`$times` must be at least 1.

When you supply `$onlyOn`, it must be a throwable class-string. Greenlight
retries only failures with that throwable type. It does not retry other
failures.

Greenlight gives each attempt a new test instance and a new per-test scope.
Thus, state does not pass between attempts.

Each retry also starts `eventually()` and `consistently()` with a new deadline
and an empty observation log. `retryOnException()` retries a probe within the
same test attempt, while `#[Retry]` starts the whole test again.

```php
#[Test]
#[Retry(times: 2, onlyOn: NetworkException::class)]
public function fetchesRates(): void { ... }
```

## Timeout

Target: method or class.

Parameters:

```php
float $seconds
```

`$seconds` must be greater than zero.

Fails the test if it runs longer than the configured budget.

Greenlight enforces a timeout in two layers. The worker checks elapsed time
cooperatively and fails a test that exceeds its budget. If the worker does not
return, the orchestrator terminates it after the hard-kill grace period.

The orchestrator replaces the stopped worker and continues the run.

An `eventually()` or `consistently()` matcher cannot run past the current test
timeout. If the test timeout occurs first, the failure gives the requested
duration. A blocked probe remains subject to the orchestrator hard-kill grace
period.

```php
#[Test]
#[Timeout(seconds: 5.0)]
public function convergesQuickly(): void { ... }
```

## RequiresResource

Target: method or class. Repeatable.

Parameters:

```php
string $name
```

Marks a test that requires one slot of a named resource. A name must start with
a lowercase letter or digit. After the first character, the name accepts dots,
underscores, and hyphens.

```php
#[RequiresResource('postgres')]
#[RequiresResource('payments-sandbox')]
final class OrderRepositoryTest { ... }
```

Class-level requirements apply to each method. Greenlight combines method-level
requirements with them. Multiple occurrences of the same name have no effect.

Greenlight combines requirements from all non-isolated tests in a class and
holds them until the class assignment finishes. Thus, a method requirement can
reduce concurrency for other non-isolated tests in the class. Each isolated
test has a separate assignment with its class-level and method-level
requirements.

Resources default to a limit of one. Use `resourceLimit()` in `greenlight.php`
or `--resource-limit` to set a larger limit.

The requirement controls the class start time. It does not select a concrete
resource instance or provide a lease identifier. Use `TestChannel` when every
worker can have its own instance. A smaller set of distinct instances still
needs an application-owned allocator.

Resource counts live in the current orchestrator. Other Greenlight processes,
worktrees, and shards have their own counts.

## Isolated

Target: method or class.

No parameters.

Runs the test method, or each test in the class, in a dedicated new worker.
Greenlight discards that worker after the test.

Use this for tests that modify process-global state, such as ini settings,
environment variables, or static caches.

## CoverageIgnore

Target: class, method, or function.

No parameters.

Excludes the declaration from coverage. Greenlight removes ignored lines from
the covered and executable totals. Thus, they do not change a percentage,
export, or baseline diff.

```php
final class Config
{
    #[CoverageIgnore]
    private function __construct() { ... }
}
```

```php
#[CoverageIgnore]
function dumpDebugState(): void { ... }
```

Greenlight matches the attribute name and does not load the related code.
Therefore, it does not recognize an aliased import
(`use Greenlight\Attribute\CoverageIgnore as Ignore`).

The PHPUnit comment annotations work without changes. Put
`@codeCoverageIgnore` in a docblock before a declaration. Put
`@codeCoverageIgnoreStart` and `@codeCoverageIgnoreEnd` around a block. Put
`// @codeCoverageIgnore` at the end of one line.
