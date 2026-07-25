# Attributes

Test metadata is declared with attributes from the `Greenlight\Attribute`
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

Output capture is enabled by default. Greenlight records output written through
PHP's output buffer, such as `echo` and `print`, together with notices, warnings,
and deprecations raised during the test. Reporters can then associate that
output with the test that produced it.

Direct writes to the `STDOUT` and `STDERR` stream resources bypass PHP's output
buffer and are not captured. They can also interfere with terminal output, so
tests should not use them for diagnostics. Use test attachments when diagnostic
content must be retained; see [test attachments](attachments.md).

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

If a class has multiple before-hooks, they run in declaration order.

A `SkipTest` thrown from a before-hook skips the test. Any other throwable marks
the test as errored.

## After

Target: method.

No parameters.

Marks a public method to run after each test in the class, including tests that
fail or error.

If a class has multiple after-hooks, they run in reverse declaration order. This
mirrors before-hooks as a stack.

Every after-hook is called, even if an earlier one throws. If the test did not
already fail or error, the first throwable from an after-hook becomes the test's
cause.

## DataSet

Target: method.

Parameters:

```php
string $provider
```

References a public static provider method on the same class.

The provider must return an iterable of named data sets for the test method.
Providers on other classes are not supported.

Providers run at discovery time, before any tests execute. Keep them pure: no
I/O and no global state.

Each provider key names a data set and appears in test ids and reports. Each
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

The bundled PHPStan extension validates providers statically: the provider
must exist, be public and static, and return an iterable of argument arrays,
and row shapes PHPStan can see (such as the `array{...}` return type above)
are checked against the test method's parameters. `#[DataRow]` literals get
the same check. See [static analysis with PHPStan](phpstan.md).

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
the data-set key in test ids and reports. If no label is provided, the key is
`#<position>` among the inline rows.

Inline rows are limited to values that PHP attributes can express, such as
scalars, arrays, and constants. For computed rows, ranges, or objects, use a
`#[DataSet]` provider.

`#[DataRow]` and `#[DataSet]` can be used on the same method. They share one
data-set key space, so duplicate keys are a discovery error.

```php
#[Test]
#[DataRow([1, 2, 3], label: 'small')]
#[DataRow([10, 20, 30])]
public function addsUp(int $a, int $b, int $sum): void { ... }
```

## NoExpectations

Target: method.

No parameters.

Declares that the test is expected to verify no expectations.

Use this for tests that pass by not throwing. Risky-test detection and
`--fail-on-risky` ignore tests marked with this attribute.

The attribute makes the intent explicit, so a deliberate zero-expectation test is
not confused with a forgotten assertion.

An `eventually()` or `consistently()` matcher counts as one expectation.

## Group

Target: method or class.

Repeatable.

Parameters:

```php
string $name
```

Tags a test method, or every test in a class, with a group name.

Groups can be selected at run time with `--group=<name>`. The flag is
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

The reason is required and appears in the report.

Skipped tests are not constructed.

## SkipUnless

Target: method or class.

Parameters:

```php
string $condition
mixed ...$arguments
```

`$condition` must be a class-string for `Greenlight\Core\Condition`.

Skips the test unless the condition is satisfied.

Any further attribute arguments are passed to the condition's constructor.
Arguments must be scalars or null, because they travel to parallel workers;
anything else is a discovery error. The constructor must only store them, and
evaluation happens in `isSatisfied()` without side effects:

```php
interface Condition
{
    public function isSatisfied(): bool;
}
```

The condition is evaluated in the worker at execution time, before the test class
is constructed. If the condition is not satisfied, constructor injection and
harness services are not used.

If the condition throws, the test errors instead of being skipped.

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

* `ExtensionLoaded('redis')` checks that the extension is loaded.
* `ExtensionMissing('xdebug')` checks that the extension is not loaded.
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

Retries a failing test up to `$times` additional attempts.

`$times` must be at least 1.

When `$onlyOn` is provided, it must be a throwable class-string. Only failures
caused by that throwable type are retried. Other failures fail immediately.

Each attempt gets a fresh test instance and a fresh per-test scope, so state does
not leak between attempts.

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

Timeout enforcement has two layers. Inside the worker, elapsed time is checked
cooperatively and an over-budget test is marked failed. The orchestrator also
hard-kills a worker if its current test exceeds the budget without returning.

A killed worker is replaced and the run continues.

An `eventually()` or `consistently()` duration cannot run past the current test
timeout. The failure names the requested polling duration when the test timeout
comes first. A blocked probe remains subject to the orchestrator's hard-kill
grace.

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

Marks a test as requiring one slot of a named resource. A name must start with a
lowercase letter or digit. The rest may also contain dots, underscores, and
hyphens.

```php
#[RequiresResource('postgres')]
#[RequiresResource('payments-sandbox')]
final class OrderRepositoryTest { ... }
```

Class-level requirements apply to every method. Method-level requirements are
combined with them. Repeating the same name has no effect.

Greenlight assigns work by class, so it combines the requirements from every
method and holds those resources until the class finishes. A requirement on one
method can therefore reduce concurrency for other methods in the same class.

Resources default to a limit of one. Use `resourceLimit()` in `greenlight.php`
or `--resource-limit` to set a larger limit. Other Greenlight processes and
shards have their own limits.

## Isolated

Target: method or class.

No parameters.

Runs the test method, or every test in the class, in a dedicated fresh worker.
That worker is discarded afterwards.

Use this for tests that modify process-global state, such as ini settings,
environment variables, or static caches.

## CoverageIgnore

Target: class, method, or function.

No parameters.

Excludes the declaration from coverage. Ignored lines are removed from both the
covered and executable totals, so they never move a percentage, an export, or a
baseline diff.

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

The attribute is matched by name without loading the code it's applied to, so
an aliased import (`use Greenlight\Attribute\CoverageIgnore as Ignore`) is not
recognised.

The PHPUnit comment annotations work unchanged: `@codeCoverageIgnore` in a
docblock before a declaration, `@codeCoverageIgnoreStart` and
`@codeCoverageIgnoreEnd` around a block, and a trailing `// @codeCoverageIgnore`
on a single line.
