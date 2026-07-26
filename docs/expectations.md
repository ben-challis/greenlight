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

Subject type requirements still apply under `not()`. For example,
`Expect::that(42)->not()->toStartWith('4')` fails because `toStartWith()` needs
a string.

## Matcher reference

### Identity and equality

| Matcher | Passes when |
| --- | --- |
| `toBe(mixed $expected)` | Subject and expected value are identical with `===` |
| `toEqual(mixed $expected)` | Values are deeply equal |
| `toEqualCanonicalizing(mixed $expected)` | Values are deeply equal while list order is ignored recursively |
| `toBeOneOf(mixed ...$options)` | Subject is identical to one option |
| `toBeIn(iterable $haystack)` | Subject is identical to an item in the iterable |

`toEqual()` compares integers and floats by numeric value. Other scalars compare
strictly. Arrays compare by key and recursively equal values. Objects must have
the same class and recursively equal properties, including private properties.
Enum cases compare by identity, and `DateTimeInterface` values compare by
instant at microsecond precision.

### Type predicates

| Matcher | Required subject |
| --- | --- |
| `toBeInstanceOf(string $class)` | Instance of the given class or interface |
| `toBeTrue()` | `true` |
| `toBeFalse()` | `false` |
| `toBeNull()` | `null` |
| `toBeArray()` | Array |
| `toBeString()` | String |
| `toBeInt()` | Integer |
| `toBeFloat()` | Float |
| `toBeBool()` | Boolean |
| `toBeCallable()` | Callable |
| `toBeIterable()` | Iterable |

### Strings and collections

| Matcher | Passes when |
| --- | --- |
| `toContain(mixed $needle)` | A string contains a string, or an iterable contains an identical value |
| `toHaveCount(int $count)` | A countable or traversable subject has the given count |
| `toBeEmpty()` | A string, countable, or traversable subject contains nothing |
| `toHaveLength(int $length)` | A string has the given character length, or a countable has the given count |
| `toHaveKey(int|string $key)` | An array or `ArrayAccess` subject contains the key |
| `toContainSubset(array $subset)` | An array contains each subset key with a deeply equal value |
| `toMatch(string $pattern)` | A string matches the regular expression |
| `toStartWith(string $prefix)` | A string starts with the prefix |
| `toEndWith(string $suffix)` | A string ends with the suffix |

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
A manual guard is currently required when the IDE needs type narrowing. Planned
IDE extension support will allow matcher calls to narrow the value directly.
