# Test doubles

Greenlight provides strict mocks, inert stubs, and spies that record calls. The
per-test `Doubles` service supplies these doubles. Request this service through
constructor injection:

<!-- php-example {"example":"test-doubles-example-01","file":"snippet.php","mode":"file","tools":["rector"]} -->
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

Incorrect use of the doubles API throws `InvalidDoubleUsage`. This exception
identifies incorrect test code. It is not an expectation failure.

## Double selection

* If the test expects specific calls and responses, use
  `mock(Type::class, $plan)`. An unplanned interaction fails immediately.
* If the test needs an unused dependency, use `stub(Type::class)`. Each
  intercepted interaction fails immediately.
* If the test must examine calls that return void, use `spy(Type::class)`. The
  spy records an unplanned call.

A call to a spy method that returns a value fails the test.

## Mock call plans

The plan passed to `mock()` declares expectations with `expects()`:

<!-- php-example {"example":"test-doubles-example-02","file":"snippet.php","mode":"statements","tools":["rector"]} -->
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

Greenlight examines expectations in declaration order. It uses the first
unsaturated expectation that accepts the call.

Without an argument constraint, an expectation accepts each argument list that
the method declaration permits. Greenlight rejects arguments that the method
does not declare.

## Mock responses

Each mock method that returns a value needs an explicit response:

<!-- php-example {"example":"test-doubles-example-03","file":"snippet.php","mode":"statements","tools":["rector"]} -->
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

`andReturnsSequence()` consumes one value for each call that matches. Greenlight
reports an error if a call occurs after the sequence is empty.

## Argument matches

Bare values passed to `with()` use the same deep equality as `toEqual()`:

<!-- php-example {"example":"test-doubles-example-04","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
$plan->expects('save')->with($expectedOrder);
```

Use `withNoArguments()` to require a call that supplies no arguments:

<!-- php-example {"example":"test-doubles-example-05","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
$plan->expects('loadDefaults')->withNoArguments();
```

This constraint is useful when a method has optional or variadic parameters.
`with()` requires at least one value or argument matcher.

Use `Argument` matchers for broader constraints:

<!-- php-example {"example":"test-doubles-example-06","file":"snippet.php","mode":"file","tools":["rector"]} -->
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

<!-- php-example {"example":"test-doubles-example-07","file":"snippet.php","mode":"statements","tools":["rector"]} -->
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

<!-- php-example {"example":"test-doubles-example-08","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
$events = $this->doubles->spy(EventPublisher::class);

new CheckoutService($events)->checkout();

Expect::that(
    $this->doubles->callsTo($events, 'publish'),
)->toEqual([[new OrderPlaced('order-1')]]);
```

## Supported types and limits

Double targets are interfaces or non-final, non-readonly classes.

A class double intercepts only overridable public instance methods.

Concrete final, static, and protected methods keep their original
implementations. Calls through these methods can run application code.

Greenlight does not run the class constructor when it creates a double. Prefer
an interface at the application boundary.
