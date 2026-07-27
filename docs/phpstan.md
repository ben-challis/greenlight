# Static analysis with PHPStan

Greenlight includes a PHPStan extension. The extension supplies information
about custom expectation matchers and data-provider shape rules. It also
supplies native matcher constraints that the PHP type system cannot express.

PHPStan also checks native and custom matchers used with `eventually()` and
`consistently()`.

## Setup

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

If you use [phpstan/extension-installer](https://github.com/phpstan/extension-installer),
it registers the include for you. Set only the `greenlight.configFiles`
parameter.

## Native matcher constraints

`toThrow()` can constrain the exception message with either an exact string or
a regular expression:

```php
Expect::that($callback)->toThrow(DomainException::class, message: 'Exact message');
Expect::that($callback)->toThrow(DomainException::class, matching: '/message/i');
```

A call that supplies both `message:` and `matching:` causes the
`greenlight.toThrow.messageConstraint` error. Greenlight also rejects the call
at run time. Thus, the constraint does not depend on PHPStan.

## Custom matcher checks

These checks apply when a plugin adds matchers through `ExpectationExtension`.
See [plugins](plugins.md). Built-in matchers such as `toBe()` are real methods.
Thus, PHPStan checks them without help from the extension.

Custom matchers send calls through `__call` at run time. PHPStan does not
usually check these calls. The extension loads your configuration files in the
same way as workers. It reflects each matcher closure and checks calls against
the real signature.

For example, use a plugin with these matchers:

```php
final class DigestMatchers implements ExpectationExtension
{
    public function matchers(): array
    {
        return [
            'toBeValidUuid' => static fn(mixed $subject): bool => \is_string($subject)
                && \preg_match('/^[0-9a-f-]{36}$/', $subject) === 1,
            'toHaveDigestLength' => static fn(mixed $subject, int $length): bool => \is_string($subject)
                && \strlen($subject) === $length,
        ];
    }
}
```

The extension checks calls against those closure signatures:

```php
Expect::that($id)->toBeValidUuid();     // checked: name, arguments, types
Expect::that($id)->toBeValidUuuid();    // fails analysis: unknown matcher
Expect::that($hash)->toHaveDigestLength('six'); // fails analysis: expects int
```

The same checks apply to temporal expectations:

```php
Expect::eventually(fn(): string => $hash)
    ->within(1.0)
    ->toHaveDigestLength(6);
```

If configuration files register one matcher name with different signatures,
analysis fails. PHPStan does not select one signature.

To give an IDE the same signatures, generate the helper file:

```sh
vendor/bin/greenlight ide-helper
```

## Test method checks

The extension reports a `#[Test]` method that Greenlight cannot run. A test
method must be public, non-static, and concrete.

Errors have identifiers under `greenlight.testMethod.*` (`visibility`,
`static`, `abstract`).

## Data provider checks

The extension validates data providers before a test runs. If you run analysis
first, PHPStan reports a broken provider before a test can report the error:

* The `#[DataSet]` provider must exist as a public static method. It belongs to
  the test class or the provider class in the two-argument form.
* The provider must return an iterable of argument arrays.
* PHPStan can know the exact row shape from an `array{...}` return type or an
  inline `#[DataRow]` literal. In this case, the rule checks each value against
  the applicable test method parameter. It also reports rows with too few
  or too many values.

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

```php
#[DataSet(ArithmeticDataSets::class, 'sums')]
```

Typical messages:

```
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
`returnType`, `arity`, `argument`). Thus, you can suppress a deliberate
exception inline:

```php
#[DataSet('doesNotExist')] // @phpstan-ignore greenlight.dataProvider.provider (proves the runtime error path)
```
