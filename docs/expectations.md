# Expectations

Greenlight expectations start with a subject value and apply one or more typed
matchers to it:

```php
use Greenlight\Expect\Expect;

Expect::that($order->status())->toBe(OrderStatus::Paid);
```

A matcher that does not pass throws immediately. Greenlight reports the source
location and includes expected and actual values when the matcher can provide
them.

## Chaining

`and()` starts a new chain from another value:

```php
Expect::that($response->status())->toBe(200)
    ->and($response->body())->toMatchJson('{"accepted":true}');
```

`not()` negates the next matcher only:

```php
Expect::that($result)->not()->toBeNull()
    ->and($errors)->toBeEmpty();
```

## Matcher reference

### Identity and equality

* `toBe(mixed $expected)` passes when the subject and expected value are
  identical with `===`.
* `toEqual(mixed $expected)` passes when the values are deeply equal.
* `toEqualCanonicalizing(mixed $expected)` passes when the values are deeply
  equal after list order is ignored recursively.
* `toBeOneOf(mixed ...$options)` passes when the subject is identical to one
  option.
* `toBeIn(iterable $haystack)` passes when the subject is identical to an item
  in the iterable.

`toEqual()` compares integers and floats by numeric value. Other scalars compare
strictly. Arrays compare by key and recursively equal values. Objects must have
the same class and recursively equal properties, including private properties.
Enum cases compare by identity, and `DateTimeInterface` values compare by
instant at microsecond precision.

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

Matchers that consume a `Traversable` do not rewind it afterwards.

### Numbers

Numeric matchers accept integer or float subjects:

* `toBeGreaterThan(int|float $bound)`
* `toBeGreaterThanOrEqual(int|float $bound)`
* `toBeLessThan(int|float $bound)`
* `toBeLessThanOrEqual(int|float $bound)`
* `toBeWithin(float $delta, float $of)`

`toBeWithin()` passes when the absolute difference between the subject and
`$of` is no greater than `$delta`.

### JSON

`toBeJson()` requires a string containing valid JSON.

`toMatchJson(string $expected)` decodes both strings and compares their
structures with `toEqual()` semantics. JSON object key order does not matter.

### Exceptions

`toThrow()` invokes a callable subject and checks the throwable type:

```php
Expect::that(fn() => $service->load('missing'))
    ->toThrow(NotFound::class);
```

Constrain the message by exact value or regular expression:

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

`message:` and `matching:` are mutually exclusive.

## Waiting for asynchronous state

`Expect::eventually()` calls a probe immediately, then polls until its matcher
passes or `within()` expires:

```php
Expect::eventually(fn() => $repository->find($id))
    ->pollEvery(0.100)
    ->within(5.0)
    ->toEqual($expected);
```

The default polling interval is 25ms. Probe exceptions stop polling unless
their types are listed with `retryOnException()`:

```php
Expect::eventually(fn() => $client->fetch($id))
    ->retryOnException(NotFoundYet::class)
    ->within(2.0)
    ->toBeInstanceOf(Response::class);
```

`Expect::consistently()` requires the first probe result to match, then checks
for the full duration:

```php
Expect::consistently(fn() => $outbox->messagesFor($id))
    ->pollEvery(0.050)
    ->for(0.5)
    ->toHaveCount(1);
```

Each polling matcher counts as one expectation. The test timeout limits its
duration, and the worker timeout remains the hard limit for a blocked probe.

## Failing explicitly

Use `Fail::because()` when a test reaches an invalid state that does not fit a
matcher:

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
Use a manual guard when the IDE needs type narrowing.
