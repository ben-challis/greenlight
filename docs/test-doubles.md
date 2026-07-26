# Test doubles

Greenlight provides strict mocks, inert stubs, and spies that record calls. The
per-test `Doubles` service supplies these doubles. Request this service through
constructor injection:

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

Greenlight verifies mocks when the test ends.

## Double selection

* If the test expects specific calls and responses, use
  `mock(Type::class, $plan)`. An unplanned interaction fails immediately.
* If the test needs an unused dependency, use `stub(Type::class)`. Each
  interaction fails immediately.
* If the test must examine void-returning calls after they occur, use
  `spy(Type::class)`. The spy records an unplanned call.

A call to a value-returning method on a spy fails the test.

## Mock call plans

The plan passed to `mock()` declares expectations with `expects()`:

```php
$plan->expects('reserve')
    ->with($sku, 2)
    ->once()
    ->andReturns(true);
```

Each method expectation has a default cardinality of at least one call. Use one
of these methods to change its cardinality:

* `once()`
* `times(int $count)`
* `atLeast(int $count)`
* `never()`

A call that does not match an expectation fails immediately. At teardown, an
unmet expectation fails the test. Greenlight reports all unmet expectations
together.

## Mock responses

Each value-returning mock method needs an explicit response:

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

`andReturnsSequence()` consumes one value for each call that matches. A call
after the sequence is empty causes an authoring error.

## Argument matches

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

## Argument capture

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

`values()` returns each captured value. `value()` returns the last value. It
fails if `Argument::captor()` did not capture a value.

If a plan must capture more than one argument, put an explicit
`Argument::captor()` inside `with()` for each argument.

## Spy calls

`callsTo()` returns argument lists in call order:

```php
$events = $this->doubles->spy(EventPublisher::class);

new CheckoutService($events)->checkout();

Expect::that(
    $this->doubles->callsTo($events, 'publish'),
)->toEqual([[new OrderPlaced('order-1')]]);
```

## Supported types and limits

Greenlight can create doubles for interfaces and non-final classes. It does not
run the class constructor when it creates a double.

Greenlight rejects final classes, readonly classes, and enums. It does not
support partial mocks or static method interception. Prefer a double for an
interface at the application boundary.
