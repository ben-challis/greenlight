# Static analysis with PHPStan

Greenlight includes a PHPStan extension. The extension supplies information
about custom expectation matchers and data-provider shape rules. It also
supplies native matcher constraints that the PHP type system cannot express.

## Setup

Install PHPStan 2.2 or a later 2.x release as a development dependency:

```console
composer require --dev phpstan/phpstan:^2.2
```

Include the extension in your PHPStan configuration. Set the Greenlight
configuration files:

```neon
includes:
    - vendor/greenlight/greenlight/extension.neon

parameters:
    greenlight:
        configFiles:
            - greenlight.php
```

Use `configFiles` only for custom matcher checks. The data-provider and native
matcher rules work without it.

## Double checks

The extension checks a constant method name in `Doubles::callsTo()` against the
doubled type:

<!-- php-example {"example":"phpstan-example-01","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
$events = $doubles->spy(EventPublisher::class);

$doubles->callsTo($events, 'publish'); // checked against EventPublisher
$doubles->callsTo($events, 'publsih'); // fails analysis: unknown method
```

PHP method names are not case-sensitive. Thus, the check accepts a method name
with different letter case. Greenlight checks a dynamic method name at run time.

Method errors use the `greenlight.doubles.callsToMethod` identifier.

For a constant method name, the extension also supplies the declared argument
types for each recorded call:

<!-- php-example {"example":"phpstan-example-02","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
$calls = $doubles->callsTo($events, 'publish');
$event = $calls[0][0]; // the declared type of EventPublisher::publish() argument 1
```

The result keeps optional parameters and variadic parameters. A dynamic method
name keeps the documented `list<list<mixed>>` return type.

The extension also checks known types in `mock()`, `stub()`, and `spy()`.
Final classes, readonly classes, enums, and traits cause analysis errors. Use
an interface or a non-final class.

Factory type errors use the `greenlight.doubles.doubleableType` identifier.

## Attribute argument checks

The extension reports constant attribute arguments that Greenlight cannot use:

* `#[Retry]` requires a positive number of additional attempts.
* `#[SkipUnless]` can transfer only scalar values or null to a worker.
* `#[Timeout]` requires a finite number greater than zero.
* `#[RequiresResource]` requires a canonical resource name.

Errors have identifiers under `greenlight.attributeArgument.*` (`retry`,
`skipUnless`, `timeout`, `resource`).

## Float argument checks

PHPStan does not have float range types. The extension checks constant values
for these method arguments:

* `CoverageBuilder::minimumPercentage()` accepts a value from `0` through
  `100`, with at most two decimal places.
* `toBeWithin()` accepts a finite tolerance of zero or more.
* `pollEvery()` accepts a finite duration of at least `0.001` seconds.
* `within()` and `for()` accept a finite duration greater than zero.

Coverage errors use `greenlight.coverageBuilderArgument.*`. Expectation errors
use `greenlight.expectationArgument.tolerance` and
`greenlight.expectationArgument.duration`. Greenlight checks unresolved values
at run time.

If you use [phpstan/extension-installer](https://github.com/phpstan/extension-installer),
it registers the include for you. Set only the `greenlight.configFiles`
parameter.

## Native matcher constraints

`toThrow()` can constrain the exception message with an exact string or a
regular expression. A Throwable instance requires the exact object. A typed
callback can specify and check the throwable:

<!-- php-example {"example":"phpstan-example-03","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::that($callback)->toThrow(DomainException::class, message: 'Exact message');
Expect::that($callback)->toThrow(DomainException::class, matching: '/message/i');
Expect::that($callback)->toThrow($failure);
Expect::that($callback)->toThrow(
    static function (DomainException $error): void {
        Expect::that($error->getPrevious())->toBeInstanceOf(LengthException::class);
    },
);
```

A call that supplies both `message:` and `matching:` causes the
`greenlight.toThrow.messageConstraint` error. Greenlight also rejects the call
at run time. Thus, the constraint does not depend on PHPStan.

A call that supplies a message constraint with a throwable callback causes the
`greenlight.toThrow.callbackConstraint` error. The generic closure signature
reports incompatible parameter and return types. The
`greenlight.toThrow.callback` error reports constraints that the signature
cannot express. Greenlight applies all callback checks at run time.

A call that supplies a message constraint with a Throwable instance causes the
`greenlight.toThrow.instanceConstraint` error. Greenlight also rejects the call
at run time.

The subject for `toThrow()` must be callable. The extension reports a known
incompatible subject before the test runs:

<!-- php-example {"example":"phpstan-example-04","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::that(42)->toThrow(DomainException::class);
```

This call causes the `greenlight.toThrow.subjectType` error. The extension
does not report the error for a `mixed` subject. Greenlight validates unresolved
subject types at run time.

## Mock plan checks

The extension keeps the doubled type through the `MockPlan` configurator. It
checks constant method names before a test runs.

The extension reports these mock plan errors:

* The planned method does not exist or Greenlight cannot intercept it.
* `withNoArguments()` cannot satisfy the required parameter count.
* `with()` supplies too few or too many arguments.
* A value or statically typed matcher in `with()` cannot match the method
  parameter.
* A cardinality or capture position is outside its permitted range.
* A configured result does not match the method return type.
* An answer closure does not accept the method arguments or return its type.
* A return sequence has no values.

The extension checks the value type of `ArgumentMatcher<T>`. It reports an
error when a known `T` cannot overlap the declared parameter type. It leaves
`mixed` and unresolved matcher types for run-time validation.

Errors use the `greenlight.mockPlan.*` identifiers. The final identifier part
is `method`, `arity`, `argument`, `cardinality`, `answer`, or `capturePosition`.
Greenlight validates each plan at run time when PHPStan cannot resolve a method
name or argument list.

For a constant method name and capture position, `captureArgument()` keeps the
selected parameter type. Its `value()` and `values()` results use that type.
A dynamic method name or position keeps the documented `mixed` value type.

## Custom matcher checks

These checks apply when a plugin adds matchers through `ExpectationExtension`.
See [plugins](plugins.md). Built-in matchers such as `toBe()` are real methods
on `Expectation`. The extension supplies their signatures on temporal chains.

Temporal matcher calls and custom matcher calls use `__call()` at run time.
PHPStan cannot infer their signatures from `__call()`. The extension reflects
native matcher methods from `Expectation`. It also loads configuration files
and reflects each custom matcher closure.

The declared closure return type must be compatible with `bool`. PHPStan leaves
an absent or `mixed` return type unresolved.

For example, use a plugin with these matchers:

<!-- php-example {"example":"phpstan-example-05","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
final class DigestMatchers implements ExpectationExtension
{
    public function matchers(): array
    {
        return [
            'toBeValidUuid' => static fn(string $subject): bool =>
                \preg_match('/^[0-9a-f-]{36}$/', $subject) === 1,
            'toHaveDigestLength' => static fn(string $subject, int $length): bool =>
                \strlen($subject) === $length,
        ];
    }
}
```

The extension checks calls against those closure signatures:

<!-- php-example {"example":"phpstan-example-06","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::that($id)->toBeValidUuid();     // checked: name, arguments, types
Expect::that($id)->toBeValidUuuid();    // fails analysis: unknown matcher
Expect::that($hash)->toHaveDigestLength('six'); // fails analysis: expects int
Expect::that(123)->toBeValidUuid();      // fails analysis: expects a string subject
```

The first closure parameter declares the accepted subject type. PHPStan gets
this type from `that()` and temporal probes.

Each custom matcher returns the same typed chain. Thus, later custom matchers
receive the same subject type.

The same checks apply to temporal expectations:

<!-- php-example {"example":"phpstan-example-07","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::eventually(fn(): string => $hash)
    ->within(1.0)
    ->toHaveDigestLength(6);
```

Temporal return types mix in the native `Expectation<T>` matcher declarations.
Thus, native and extension matchers keep the probe subject type.

If configuration files register one matcher name with different parameter or
return types, analysis fails. PHPStan does not select one signature.

Subject-type errors use the `greenlight.extensionMatcher.subjectType`
identifier. Return-type errors use the
`greenlight.extensionMatcher.returnType` identifier. Matcher argument errors
keep the PHPStan error identifier.

To give an IDE the same signatures, generate the helper file:

```sh
vendor/bin/greenlight ide-helper
```

## Expectation subject types

PHPStan narrows the original subject after a synchronous type expectation
passes:

<!-- php-example {"example":"phpstan-subject-refinement","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
/** @var FileCoverage|null $file */
Expect::that($file)->not()->toBeNull();

$file->coveredLines; // PHPStan knows that this value is FileCoverage.
```

PHPStan applies this refinement to these native matchers:

* `toBeInstanceOf()`
* `toBeTrue()` and `toBeFalse()`
* `toBeNull()`
* `toBeArray()`, `toBeString()`, `toBeInt()`, `toBeFloat()`, and `toBeBool()`
* `toBeCallable()` and `toBeIterable()`

The call must contain `Expect::that()` and the matcher in the same expression.
PHPStan also follows `because()` and `not()` in that expression.

A stored expectation does not narrow the original subject. A temporal
expectation does not narrow a value outside its probe.

## Constant expectation argument checks

The extension reports constant expectation arguments that Greenlight cannot
use:

* `toMatch()` and the `matching:` argument of `toThrow()` require a valid
  regular expression.
* The expected value for `toMatchJson()` must contain valid JSON.
* A constant `because()` reason must contain a non-whitespace character.

Errors have identifiers under `greenlight.expectationArgument.*` (`pattern`,
`json`, `reason`). PHPStan checks constant values before run time. Greenlight
checks unresolved values at run time.

## Test method checks

The extension reports a `#[Test]` method that Greenlight cannot run. A test
method must be public, non-static, and concrete.

A test method with required parameters must have a `#[DataRow]` or `#[DataSet]`
attribute. Without a data set, Greenlight calls the method with no arguments.

Errors have identifiers under `greenlight.testMethod.*` (`visibility`,
`static`, `abstract`, `dataSet`).

Method-level test metadata such as `#[Group]`, `#[Skip]`, `#[DataRow]`, and
`#[NoExpectations]` has no effect without `#[Test]`. The extension reports the
unused attribute with `greenlight.testAttribute.noEffect`. Lifecycle and
coverage attributes do not require `#[Test]`.

## Test constructor checks

A concrete class that contains or inherits a test can omit its constructor. If
the class declares a constructor, the constructor must be public. Each required
constructor parameter must have one class or interface type. A parameter must
have a default value if it has a scalar, union, intersection, or `object` type,
or no type. Greenlight can then resolve supported service types at run time.

Errors have identifiers under `greenlight.testConstructor.*` (`visibility`,
`parameter`).

## Lifecycle hook checks

The extension reports a `#[Before]` or `#[After]` method that Greenlight cannot
run. A lifecycle hook must be public, non-static, and concrete. It must not
require arguments.

Errors have identifiers under `greenlight.lifecycleMethod.*` (`visibility`,
`static`, `abstract`, `parameters`).

## Conditional skip checks

For `#[SkipUnless]`, PHPStan checks the transferred arguments against the
constructor of the referenced condition. It reports invalid argument counts
and types before a worker evaluates the condition.

Errors use `greenlight.skipUnlessCondition.arity` and
`greenlight.skipUnlessCondition.argument`.

## Data provider checks

The extension validates data providers before a test runs. If you run analysis
first, PHPStan reports a broken provider before a test can report the error:

* The `#[DataSet]` provider must exist as a public, static, concrete method. It
  must not require arguments. It belongs to the test class or the provider
  class in the two-argument form.
* The provider must return an iterable of argument arrays.
* Provider keys must be integers or strings.
* A provider with a return type that is always empty is invalid.
* PHPStan can know the exact row shape from an `array{...}` return type or an
  inline `#[DataRow]` literal. In this case, the rule checks each value against
  the applicable test method parameter. It also reports rows with too few
  or too many values.
* Constant inline row labels must be unique. An explicit label must not
  duplicate a generated positional label such as `#0`.

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
```php
#[Test]
#[DataSet('sums')]
#[DataRow([2, 2, 4])]
public function adds(int $left, int $right, int $expected): void { ... }

/** @return iterable<string, array{int, int, int}> */
public static function sums(): iterable
{
    yield 'ones' => [1, 1, 2];       // checked against (int, int, int)
}
```

Providers shared by multiple test classes receive the same checks:

<!-- php-example {"example":"phpstan-example-09","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
#[DataSet(ArithmeticDataSets::class, 'sums')]
```

Typical messages:

```text
Data provider sums() for adds() does not exist on PriceTest.
Data provider PriceTest::sums() must be public and static.
Data provider PriceTest::sums() must return an iterable of argument arrays, returns string.
Data provider sums() row argument #3 of adds() expects int, string given.
#[DataRow] supplies 2 arguments, but adds() expects exactly 3.
```

Some rows have no exact shape in PHPStan. For example, a provider can have the
type `iterable<array<mixed>>`. PHPStan requires only that each row is an array.
Greenlight checks the array contents at run time.

Errors have identifiers under `greenlight.dataProvider.*` (`provider`,
`parameters`, `returnType`, `keyType`, `empty`, `duplicateKey`, `arity`,
`argument`). Thus, you can suppress a deliberate exception inline:

<!-- php-example {"example":"phpstan-example-10","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
#[DataSet('doesNotExist')] // @phpstan-ignore greenlight.dataProvider.provider (proves the runtime error path)
```

## Native matcher subject reference

Some native matchers accept only specified subject types. The extension reports
a known incompatible subject before the test runs:

| Required subject | Matchers |
| --- | --- |
| `string` or `iterable` | `toContain()` |
| `Countable` or `Traversable` | `toHaveCount()` |
| `string`, `array`, `Countable`, or `Traversable` | `toBeEmpty()` |
| `string`, `array`, or `Countable` | `toHaveLength()` |
| `array` or `ArrayAccess` | `toHaveKey()` |
| `array` | `toContainSubset()` |
| `int` or `float` | Numeric comparison matchers and `toBeWithin()` |
| `string` | String and JSON matchers |

Subject errors use the `greenlight.nativeMatcher.subjectType` identifier. A
string subject also requires a string `toContain()` needle. This error uses
`greenlight.toContain.needleType`.

The extension does not report these errors for a `mixed` subject. Greenlight
validates unresolved subject types at run time.
