# Attributes

All test metadata is declared with attributes from the `Greenlight\Attribute` namespace. There are no method-name conventions and no annotations. Attributes targeting a class apply to every test in it.

## Test

Target: method. Parameters: `bool $capture = true`.

Marks a public method as a test. Output capture is on by default: anything the test writes to stdout or stderr is captured and attached to the result instead of corrupting the reporter stream. Pass `#[Test(capture: false)]` for tests that need to debug output themselves.

```php
#[Test]
public function totalsAreRounded(): void { ... }

#[Test(capture: false)]
public function printsDirectly(): void { ... }
```

## Before

Target: method. No parameters.

Marks a public method to run before each test in the class. When a class has several before-hooks they run in declaration order. A `SkipTest` thrown from a before-hook skips the test; any other throwable errors it.

## After

Target: method. No parameters.

Marks a public method to run after each test in the class, including after failures and errors. Multiple after-hooks run in reverse declaration order, mirroring the before-hooks like a stack. Every after-hook is invoked even if an earlier one throws; the first throwable wins as the test's cause when nothing else failed first.

## DataSet

Target: method. Parameters: `string $provider`.

References a public static method on the same class that returns an iterable of named data sets for the test method. Providers on other classes are not supported. The provider runs at discovery time, before any test executes, so it must be pure: no I/O, no global state. Keys name the data sets and appear in test ids and reports; each value is the argument list for one invocation.

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

## DataRow

Target: method. Repeatable. Parameters: `array $arguments`, `?string $label = null`.

One inline data set: the array holds the arguments in parameter order, and the label (or `#<position>` among the rows when unlabelled) becomes the data-set key in test ids. Rows are limited to what attributes can express (scalars, arrays, constants); for computed rows, ranges, or objects, use a `#[DataSet]` provider. Both can sit on the same method and share one key space; a duplicate key is a discovery error.

```php
#[Test]
#[DataRow([1, 2, 3], label: 'small')]
#[DataRow([10, 20, 30])]
public function addsUp(int $a, int $b, int $sum): void { ... }
```

## Group

Target: method or class. Repeatable. Parameters: `string $name`.

Tags a test method, or every test in the class, with a group name for filtering. Select groups at run time with `--group=<name>` (repeatable). `list-tests` honours the same filter.

```php
#[Group('slow')]
#[Group('io')]
final class ImportTest { ... }
```

## Skip

Target: method or class. Parameters: `string $reason`.

Skips the test method, or every test in the class, unconditionally. The reason is mandatory and appears in the report. Skipped tests are never constructed.

## SkipUnless

Target: method or class. Parameters: `string $condition`, a class-string of `Greenlight\Core\Condition`.

Skips the test unless the condition is satisfied. The condition class must be constructible without arguments and side-effect free:

```php
interface Condition
{
    public function isSatisfied(): bool;
}
```

The condition is evaluated in the worker at execution time, before the test class is constructed, so an unsatisfied condition never triggers constructor injection or harness services. A condition that throws errors the test rather than skipping it.

```php
#[Test]
#[SkipUnless(RedisIsRunning::class)]
public function storesSessionsInRedis(): void { ... }
```

## Retry

Target: method or class. Parameters: `int $times`, `?string $onlyOn = null`.

Retries a failing test up to `$times` additional attempts. `$times` must be at least 1. When `$onlyOn` is given (a throwable class-string), only failures caused by that throwable type are retried; anything else fails immediately. Each attempt constructs a fresh test instance and opens a fresh per-test scope, so no state leaks between attempts.

```php
#[Test]
#[Retry(times: 2, onlyOn: NetworkException::class)]
public function fetchesRates(): void { ... }
```

## Timeout

Target: method or class. Parameters: `float $seconds`, must be greater than zero.

Fails the test when it runs longer than the budget. Enforcement is layered: inside the worker the elapsed time is checked cooperatively and an over-budget test is marked failed, and the orchestrator additionally hard-kills a worker whose test exceeds its budget without returning, so a genuinely hung test cannot stall the run. A killed worker is replaced and the run continues.

```php
#[Test]
#[Timeout(seconds: 5.0)]
public function convergesQuickly(): void { ... }
```

## Isolated

Target: method or class. No parameters.

Runs the test method, or every test in the class, in a dedicated fresh worker that is discarded afterwards. Use it for tests that mutate process-global state (ini settings, environment variables, static caches) that would poison a reused worker.

## The SkipTest signal

`Greenlight\Plugin\SkipTest` is not an attribute but a control-flow exception for skips that can only be decided mid-test. Throw it from a test method, a before-hook, or a plugin's `beforeTest` subscriber to report the test as skipped with the given reason:

```php
use Greenlight\Plugin\SkipTest;

#[Test]
public function talksToSandbox(): void
{
    if (!$this->sandbox->isReachable()) {
        throw new SkipTest('The payment sandbox is unreachable.');
    }
    ...
}
```

Prefer `#[Skip]` or `#[SkipUnless]` when the decision is static or expressible as a condition class; they skip before construction and cost nothing.
