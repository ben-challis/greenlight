# Expectations

A Greenlight expectation starts with a subject value. It applies one or more
typed matchers to that value:

<!-- php-example {"example":"expectations-example-01","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Expect\Expect;

Expect::that($order->status())->toBe(OrderStatus::Paid);
```

A matcher throws immediately if it does not pass. Greenlight reports the source
location. It also reports expected and actual values when the matcher supplies
them.

## Matcher chains

Matchers in a chain use the same subject:

<!-- php-example {"example":"expectations-example-02","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::that($response->body())
    ->toBeString()
    ->toMatchJson('{"accepted":true}');
```

Start a separate expectation for each subject.

`not()` negates the next matcher only:

<!-- php-example {"example":"expectations-example-03","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::that($errors)
    ->not()->toBeEmpty()
    ->toHaveCount(1);
```

`because()` adds a reason to the next matcher only:

<!-- php-example {"example":"expectations-example-04","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::that($order->isOpen())
    ->because('a refund requires an open order')
    ->toBeTrue();
```

When the matcher fails, the failure message puts the reason after the word
`because`:

```text
Expected false to be true because a refund requires an open order.
```

The reason must not be empty. Temporal expectation chains also accept
`because()`.

## Matcher reference

### Identity and equality

* `toBe(mixed $expected)` passes when the subject and expected value are
  identical with `===`.
* `toEqual(mixed $expected)` passes when the values are deeply equal.
* `toEqualCanonicalizing(mixed $expected)` passes when the values are deeply
  equal after the matcher recursively ignores list order.
* `toBeOneOf(mixed ...$options)` passes when the subject is identical to one
  option.
* `toBeIn(iterable $haystack)` passes when the subject is identical to an item
  in the iterable.

`toEqual()` compares integers and floats by numeric value. It compares other
scalars strictly. It compares arrays by key and recursively equal values.
Objects must have the same class and recursively equal properties. This rule
includes private properties.

Object comparisons distinguish shared objects from equal copies. They also
require equal cycle shapes.

Enum cases compare by identity.
`DateTimeInterface` values compare by instant at microsecond precision.

### Type predicates

* `toBeInstanceOf(string $class)` requires an instance of the given class or
  interface.
* `toBeTrue()` requires `true`.
* `toBeFalse()` requires `false`.
* `toBeNull()` requires `null`.
* `toBeArray()` requires an array.
* `toBeString()` requires a string.
* `toBeInt()` requires an integer.
* `toBeFloat()` requires a float.
* `toBeBool()` requires a boolean.
* `toBeCallable()` requires a callable.
* `toBeIterable()` requires an iterable.

### Strings and collections

* `toContain(mixed $needle)` checks that a string contains a string, or that an
  iterable contains an identical value.
* `toHaveCount(int $count)` checks that a countable or traversable subject has
  the given count.
* `toBeEmpty()` checks that a string, countable, or traversable subject contains
  nothing.
* `toHaveLength(int $length)` checks the character length of a string or the
  count of a countable subject.
* `toHaveKey(int|string $key)` checks that an array or `ArrayAccess` subject
  contains the key.
* `toContainSubset(array $subset)` checks that an array contains every subset
  key with a deeply equal value.
* `toMatch(string $pattern)` checks that a string matches the regular
  expression.
* `toStartWith(string $prefix)` checks that a string starts with the prefix.
* `toEndWith(string $suffix)` checks that a string ends with the suffix.

Matchers that consume a `Traversable` do not rewind it after consumption.

### Numbers

Numeric matchers accept integer or float subjects:

* `toBeGreaterThan(int|float $bound)`
* `toBeGreaterThanOrEqual(int|float $bound)`
* `toBeLessThan(int|float $bound)`
* `toBeLessThanOrEqual(int|float $bound)`
* `toBeWithin(float $delta, float $of)`

`toBeWithin()` passes when the absolute difference between the subject and
`$of` is no greater than `$delta`. Use a finite tolerance of zero or more.

### JSON

`toBeJson()` requires a string that contains valid JSON.

`toMatchJson(string $expected)` decodes both strings and compares their
structures with `toEqual()` semantics. JSON object key order does not matter.

### Exceptions

`toThrow()` invokes a callable subject and checks the throwable type:

<!-- php-example {"example":"expectations-example-05","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::that(fn() => $service->load('missing'))
    ->toThrow(NotFound::class);
```

Pass a Throwable instance to require the callable to throw that exact object:

<!-- php-example {"example":"expectations-example-06","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
$failure = new DomainException('Order is closed.');

Expect::that(fn() => throw $failure)
    ->toThrow($failure);
```

Constrain the message by exact value or regular expression:

<!-- php-example {"example":"expectations-example-07","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::that($callback)->toThrow(
    DomainException::class,
    message: 'Order is closed.',
);

Expect::that($callback)->toThrow(
    DomainException::class,
    matching: '/closed/i',
);
```

`message:` and `matching:` are mutually exclusive. Use them only with a
throwable class-string. A Throwable instance already specifies one exact
object.

A typed callback can specify the throwable type and check the caught
throwable. Greenlight runs it only after the throwable type matches:

<!-- php-example {"example":"expectations-example-08","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::that(fn() => $fixtureManager->start())
    ->toThrow(
        static function (IntegrationFixtureError $error): void {
            Expect::that($error->getPrevious())
                ->toBeInstanceOf(LengthException::class);
        },
    );
```

Declare one named, non-null Throwable parameter type by value. Return `void`
from the callback. The parameter type specifies the expected throwable class.

The callback can contain ordinary expectations. A failed callback expectation
keeps its diagnostic. An `eventually()` expectation retries this failure.

With `not()`, a failed callback expectation means that the throwable does not
match. Thus, the negated `toThrow()` expectation passes. Other throwables from
the callback stop the matcher. An instance constraint matches only the exact
object. A different instance passes the negated expectation.

An `eventually()` expectation retries when the callable throws a different
instance. It passes when the callable throws the specified object.

## Asynchronous state

`Expect::eventually()` calls a probe immediately. It then polls until its
matcher passes or `within()` expires:

<!-- php-example {"example":"expectations-example-09","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::eventually(fn() => $repository->find($id))
    ->pollEvery(0.100)
    ->within(5.0)
    ->toEqual($expected);
```

The default poll interval is 25 ms. A probe exception stops the polls unless
`retryOnException()` lists its type:

<!-- php-example {"example":"expectations-example-10","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::eventually(fn() => $client->fetch($id))
    ->retryOnException(NotFoundYet::class)
    ->within(2.0)
    ->toBeInstanceOf(Response::class);
```

`retryOnException()` accepts `Exception` subclasses, but not `Error` types.

`Expect::consistently()` requires the first probe result to match, then checks
for the full duration:

<!-- php-example {"example":"expectations-example-11","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::consistently(fn() => $outbox->messagesFor($id))
    ->pollEvery(0.050)
    ->for(0.5)
    ->toHaveCount(1);
```

For both temporal chains, `pollEvery()` accepts a finite duration of at least
0.001 seconds. `within()` and `for()` accept finite durations greater than zero.

Each temporal matcher counts as one expectation. The test timeout limits its
duration. The worker timeout remains the hard limit for a blocked probe.

## Explicit failures

If a test reaches an invalid state that does not fit a matcher, use
`Fail::because()`:

<!-- php-example {"example":"expectations-example-12","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Expect\Fail;

if (!$response instanceof SuccessResponse) {
    Fail::because(\sprintf(
        'Expected SuccessResponse, got %s.',
        \get_debug_type($response),
    ));
}
```

The call counts as an expectation and reports itself as the failure location.
If the IDE cannot determine the type, use a manual guard.
