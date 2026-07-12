# Static analysis with PHPStan

Greenlight ships a PHPStan extension. It teaches PHPStan two things: the
custom expectation matchers your config registers, and the shape rules for
`#[DataSet]` and `#[DataRow]` data providers.

## Setup

Include the extension in your PHPStan configuration and point it at your
Greenlight config files:

```neon
includes:
    - vendor/greenlight/greenlight/extension.neon

parameters:
    greenlight:
        configFiles:
            - greenlight.php
```

`configFiles` is only needed for custom matcher checking. The data provider
rule works without it.

If you use [phpstan/extension-installer](https://github.com/phpstan/extension-installer),
it registers the include for you; you only set the `greenlight.configFiles`
parameter.

## Custom matcher checking

This applies when a plugin adds its own matchers to the expectation chain
through `ExpectationExtension` (see [writing plugins](plugins.md)). Built-in
matchers like `toBe()` are real methods, so PHPStan checks them without any
help.

Custom matchers are different: they dispatch through `__call` at run time,
which PHPStan would normally wave through. With the extension, PHPStan loads
your config files the same way workers do, reflects each matcher closure,
and checks calls against the real signature.

Given a plugin registering these matchers:

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

calls are checked against those closure signatures:

```php
Expect::that($id)->toBeValidUuid();     // checked: name, arguments, types
Expect::that($id)->toBeValidUuuid();    // fails analysis: unknown matcher
Expect::that($hash)->toHaveDigestLength('six'); // fails analysis: expects int
```

The same matcher name registered with two different signatures across config
files fails the run loudly rather than picking one.

For IDE autocomplete with the same signatures, generate the helper file:

```sh
vendor/bin/greenlight ide-helper
```

## Data provider checking

The extension validates data providers before anything runs, so a broken
provider fails analysis instead of surfacing as errored tests:

* A `#[DataSet]` provider must exist on the test class as a public static
  method.
* It must return an iterable of argument arrays.
* Where PHPStan knows a row's exact shape, from an `array{...}` return type
  or an inline `#[DataRow]` literal, the rule checks each value against the
  matching test method parameter and flags rows with too few or too many
  values.

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

Typical messages:

```
Data provider sums() for adds() does not exist on PriceTest.
Data provider PriceTest::sums() must be public and static.
Data provider PriceTest::sums() must return an iterable of argument arrays, returns string.
Data provider sums() row argument #3 of adds() expects int, string given.
#[DataRow] supplies 2 arguments, but adds() expects exactly 3.
```

Rows PHPStan cannot narrow to an exact shape, such as a provider typed
`iterable<array<mixed>>`, only need to be arrays; the runtime checks their
contents instead.

Errors carry identifiers under `greenlight.dataProvider.*` (`provider`,
`returnType`, `arity`, `argument`), so you can suppress a deliberate
exception inline:

```php
// @phpstan-ignore greenlight.dataProvider.provider (proves the runtime error path)
#[DataSet('doesNotExist')]
```
