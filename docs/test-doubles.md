# Test doubles

Greenlight provides strict mocks, inert stubs, and recording spies through the
per-test `Doubles` service. Ask for it through constructor injection:

```php
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;

final class CheckoutServiceTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function chargesTheOrder(): void
    {
        $gateway = $this->doubles->mock(
            PaymentGateway::class,
            function (MockPlan $plan): void {
                $plan->expects('charge')
                    ->with(1999, 'GBP')
                    ->once()
                    ->andReturns('payment-123');
            },
        );

        $payment = new CheckoutService($gateway)->checkout(1999, 'GBP');

        Expect::that($payment->id)->toBe('payment-123');
    }
}
```

Mocks are verified automatically when the test ends.

## Choosing a double

* Use `mock(Type::class, $plan)` when the test expects specific calls and
  responses. An unplanned interaction fails immediately.
* Use `stub(Type::class)` when a dependency must exist but must not be used.
  Any interaction fails immediately.
* Use `spy(Type::class)` when the test needs to inspect void-returning calls
  afterwards. An unplanned call is recorded.

Calling a value-returning method on a spy fails the test.

## Planning mock calls

The plan passed to `mock()` declares expectations with `expects()`:

```php
$plan->expects('reserve')
    ->with($sku, 2)
    ->once()
    ->andReturns(true);
```

Each method expectation defaults to at least one call. Change its cardinality
with:

* `once()`
* `times(int $count)`
* `atLeast(int $count)`
* `never()`

A call that matches no expectation fails immediately. Unmet expectations fail
at teardown, and Greenlight reports all of them together.

## Configuring responses

Every value-returning mock method needs an explicit response:

```php
$plan->expects('nextId')->andReturns('id-1');

$plan->expects('nextId')
    ->times(2)
    ->andReturnsSequence('id-1', 'id-2');

$plan->expects('convert')
    ->andReturnsUsing(fn (int $value): int => $value * 2);

$plan->expects('load')
    ->andThrows(new NotFound('Missing record.'));
```

`andReturnsSequence()` consumes one value per matching call. Calling the method
after the sequence is exhausted is an authoring error.

## Matching arguments

Bare values passed to `with()` use the same deep equality as `toEqual()`:

```php
$plan->expects('save')->with($expectedOrder);
```

Use `Argument` matchers for broader constraints:

```php
use Greenlight\Doubles\Argument;

$plan->expects('save')->with(
    Argument::type(Order::class),
    Argument::predicate(
        fn (int $attempt): bool => $attempt > 0,
        'a positive attempt',
    ),
    Argument::any(),
);
```

Available matchers are:

* `Argument::any()`
* `Argument::type(string $type)`
* `Argument::predicate(Closure $predicate, string $description = 'predicate')`
* `Argument::equals(mixed $value)`
* `Argument::captor()`

## Capturing arguments

Capture one argument from every matched call:

```php
$captor = $plan->expects('save')
    ->times(2)
    ->andReturns(true)
    ->captureArgument(0);

// Exercise the subject.

Expect::that($captor->values())->toHaveCount(2);
Expect::that($captor->value())->toBeInstanceOf(Order::class);
```

`values()` returns every captured value. `value()` returns the last one and
fails if nothing was captured.

An explicit `Argument::captor()` can be placed directly inside `with()` when a
plan needs more than one captor.

## Reading spy calls

`callsTo()` returns argument lists in call order:

```php
$events = $this->doubles->spy(EventPublisher::class);

new CheckoutService($events)->checkout();

Expect::that(
    $this->doubles->callsTo($events, 'publish'),
)->toEqual([[new OrderPlaced('order-1')]]);
```

## Supported types and limits

Interfaces and non-final classes can be doubled. Greenlight does not run the
class constructor when it creates a double.

Final classes, readonly classes, and enums are rejected. Greenlight does not
support partial mocks or static method interception. Prefer doubling an
interface at the application boundary.
