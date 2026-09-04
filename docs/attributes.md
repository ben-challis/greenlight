# Attributes

Declare test metadata with attributes from the `Greenlight\Attribute`
namespace.

There are no method-name conventions and no annotations. Attributes on a class
apply to every test method in that class.

## Test

Target: method.

Parameters:

<!-- php-example {"mode":"display","reason":"Shows attribute parameters without the surrounding declaration."} -->
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

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
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

<!-- php-example {"mode":"display","reason":"Shows attribute parameters without the surrounding declaration."} -->
```php
string $provider
?string $method = null
```

With one argument, references a public static provider method on the test
class. With two arguments, the first argument is a provider class. The second
argument is its public static method:

Use nonempty provider class and method names.

<!-- php-example {"example":"attributes-example-04","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
#[DataSet('currencies')]
```

or:

<!-- php-example {"example":"attributes-example-05","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
#[DataSet(CurrencyDataSets::class, 'currencies')]
```

Return a non-empty iterable from the provider. Use one argument array for each
test invocation.

Greenlight invokes the provider during discovery to create the execution plan.
The plan contains data-set keys, not argument values. Greenlight invokes the
provider again once for each worker-side class assignment to create the values
in that worker. Return every planned key on each invocation.

Keep providers pure, deterministic, and free of I/O and global state. Each
invocation has a five-second time budget. Greenlight checks elapsed time after the call and as
it reads rows. This check cannot interrupt a blocked provider.

Use an integer or string for each provider key. Greenlight changes an integer
key to `#<key>`. It keeps a non-empty printable string key unchanged. It changes
an empty or nonprintable string key to the first eight hexadecimal characters
of its SHA-256 hash. The normalized key appears in test IDs and reports.
Duplicate normalized keys cause a discovery error.

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
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

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
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

<!-- php-example {"mode":"display","reason":"Shows attribute parameters without the surrounding declaration."} -->
```php
array $arguments
?string $label = null
```

Adds one inline data set.

`$arguments` contains the test arguments in parameter order. The label becomes
the data-set key in test IDs and reports. Without a label, Greenlight uses
`#<position>` as the key for the inline row.

An explicit label must not be empty.

Inline rows can contain only values that PHP attributes can express. Examples
include scalars, arrays, and constants. Use a `#[DataSet]` provider for
calculated rows, ranges, or objects.

You can use `#[DataRow]` and `#[DataSet]` on the same method. They use one
data-set key space. Thus, duplicate keys cause a discovery error.

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
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

An `eventually()` or `consistently()` matcher counts as one expectation.

## Group

Target: method or class.

Repeatable.

Parameters:

<!-- php-example {"mode":"display","reason":"Shows attribute parameters without the surrounding declaration."} -->
```php
string $name
```

Tags a test method, or every test in a class, with a group name.

Select groups at run time with `--group=<name>`. The flag is
repeatable. `list-tests` applies the same filter.

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
```php
#[Group('slow')]
#[Group('io')]
final class ImportTest { ... }
```

## Skip

Target: method or class.

Parameters:

<!-- php-example {"mode":"display","reason":"Shows attribute parameters without the surrounding declaration."} -->
```php
string $reason
```

Skips the test method, or every test in the class, unconditionally.

You must give a reason. The reason appears in the report.

Greenlight does not construct skipped tests.

## SkipUnless

Target: method or class.

Parameters:

<!-- php-example {"mode":"display","reason":"Shows attribute parameters without the surrounding declaration."} -->
```php
string $condition
mixed ...$arguments
```

Use `$condition` to name an instantiable class that implements
`Greenlight\Condition\Condition`.

Skips the test if the condition is false.

Greenlight passes the remaining attribute arguments to the condition
constructor. Use only scalar values or null because Greenlight sends them to
parallel workers. Another argument type causes a discovery error. Only store
the arguments in the constructor. Evaluate the condition without side effects
in `isSatisfied()`:

<!-- php-example {"example":"attributes-example-14","file":"snippet.php","mode":"file","tools":["rector"]} -->
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

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
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

<!-- php-example {"mode":"display","reason":"Shows attribute parameters without the surrounding declaration."} -->
```php
int $times
?string $onlyOn = null
```

Retries an unsuccessful test attempt up to `$times` additional attempts.

Use a `$times` value of 1 or more.

When you supply `$onlyOn`, use a throwable class-string. Greenlight retries
only when the attempt cause has that throwable type. It does not retry an
unsuccessful attempt that has no matching cause.

Greenlight gives each attempt a new test instance and a new per-test scope.
Thus, state does not pass between attempts.

Each retry also starts `eventually()` and `consistently()` with a new deadline
and an empty observation log. `retryOnException()` retries a probe within the
same test attempt, while `#[Retry]` starts the whole test again.

If a test passes after retry, reporters keep its passed outcome and attempt
count. They also report the retried pass as evidence of instability.

Use `failOnRetriedPass()` or `--fail-on-retried-pass` to fail the run for this
evidence. The policy does not change the test outcome.

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
```php
#[Test]
#[Retry(times: 2, onlyOn: NetworkException::class)]
public function fetchesRates(): void { ... }
```

## Timeout

Target: method or class.

Parameters:

<!-- php-example {"mode":"display","reason":"Shows attribute parameters without the surrounding declaration."} -->
```php
float $seconds
```

`$seconds` must be finite and greater than zero.

Fails the test if it runs longer than the configured budget.

Greenlight enforces a timeout in two layers. The worker checks elapsed time
cooperatively and fails a test that exceeds its budget. If the worker does not
return, the orchestrator terminates it after the hard-kill grace period.

Each retry starts a new timeout budget and a new hard-kill grace period.

The orchestrator replaces the stopped worker and continues the run.

An `eventually()` or `consistently()` matcher cannot run past the current test
timeout. If the test timeout occurs first, the failure gives the requested
duration. A blocked probe remains subject to the orchestrator hard-kill grace
period.

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
```php
#[Test]
#[Timeout(seconds: 5.0)]
public function convergesQuickly(): void { ... }
```

## AllowParallel

Target: class.

No parameters.

Allows Greenlight to assign tests from one class to different worker
processes. Each selected test or data set becomes one pooled assignment.

Without this attribute, Greenlight assigns all non-isolated tests in a class
as one unit. This default preserves method order and class-scope state.

Use this attribute only when all tests and data sets in the class are
independent:

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
```php
#[AllowParallel]
final class LargeImportTest
{
    #[Test]
    #[DataSet('imports')]
    public function importsOneFile(ImportCase $case): void { ... }
}
```

Greenlight preserves execution-plan order when it makes assignments. Worker
placement and completion-event order remain load-dependent.

Each assignment emits one class-started and class-finished event pair. The
`#[Before]` and `#[After]` hooks still run for each test attempt.

Each split assignment expands its data provider independently. Thus, the same
provider can run many times for one test class.

`#[AllowParallel]` is incompatible with these features:

* `#[Isolated]` on the class or one of its test methods
* a harness service with `Scope::PerClass`

Discovery rejects the combination with `#[Isolated]`. A worker reports a test
error if the class requests a per-class harness service.

Retries and timeouts remain local to one test. Resource limits apply to each
split assignment.

The attribute has no concurrency effect with one worker. Greenlight uses
worker processes for concurrency and does not use Fibers.

## RequiresResource

Target: method or class. Repeatable.

Parameters:

<!-- php-example {"mode":"display","reason":"Shows attribute parameters without the surrounding declaration."} -->
```php
string $name
```

Marks a test that requires one slot of a named resource. A name must start with
a lowercase letter or digit. After the first character, the name accepts dots,
underscores, and hyphens.

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
```php
#[RequiresResource('postgres')]
#[RequiresResource('payments-sandbox')]
final class OrderRepositoryTest { ... }
```

Class-level requirements apply to each method. Greenlight combines method-level
requirements with them. Multiple occurrences of the same name have no effect.

By default, Greenlight combines requirements from all non-isolated tests in a
class. It holds them until the class assignment finishes.

For a class with `#[AllowParallel]`, each split assignment holds only its own
class-level and method-level requirements. Each isolated test also has a
separate assignment.

Cached duration data can put small classes in one unit only when they have
identical resource sets. Greenlight does not batch a class that contains an
isolated or `#[AllowParallel]` test.

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

With process-pool execution, runs the test method, or each test in the class,
in a dedicated new worker. Greenlight discards that worker after the test.

Use this for tests that modify process-global state, such as ini settings,
environment variables, or static caches.

In-process execution cannot provide this isolation. `--workers=1` and the
automatic fallback for unavailable process functions run the complete plan in
one process. Do not use either mode when a test depends on `#[Isolated]` for
process-global state cleanup.

## CoverageIgnore

Target: class, method, or function.

No parameters.

Excludes the declaration from coverage. Greenlight removes ignored lines from
the covered and executable totals. Thus, they do not change a percentage,
export, or baseline diff.

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
```php
final class Config
{
    #[CoverageIgnore]
    private function __construct() { ... }
}
```

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
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
