# Move from PHPUnit

This guide describes concepts. It does not provide an automatic conversion.

Greenlight and PHPUnit use different test structures. A migration usually
changes the test support code, but much test logic can remain the same.

## Map the concepts

* Replace `extends TestCase` with a plain class.
* Add `#[Test]` to each public test method.
* Replace `setUp()` with `#[Before]` on a public method.
* Replace `tearDown()` with `#[After]` on a public method.
* Replace `#[DataProvider('cases')]` with `#[DataSet('cases')]`.
* Keep the static data provider on the test class.
* Replace `#[TestWith([1, 2])]` with `#[DataRow([1, 2])]`.
* Add an optional label to `#[DataRow]`.
* Replace group annotations with the repeatable `#[Group('slow')]` attribute.
* Add `#[Group('slow')]` to a method or class.
* Replace `$this->markTestSkipped($reason)` with
  `throw new SkipTest($reason)`.
* Replace requirement attributes with a `#[SkipUnless]` condition.
* Use `#[SkipUnless(ExtensionLoaded::class, 'redis')]` for an extension check.
* Replace `$this->assert...()` calls with static `Expect::that(...)` chains.
* Replace `createMock()` and `getMockBuilder()` with the injected `Doubles`
  service.
* Use the `mock()`, `stub()`, and `spy()` methods to create doubles.
* Replace `setUpBeforeClass()` static code with per-class harness services.
* Replace `#[RunInSeparateProcess]` with `#[Isolated]`.
* Replace data flow from `#[Depends]` with explicit fixture or harness state.
* Remove `#[Depends]` after the test no longer accepts the producer result.
* Keep `@codeCoverageIgnore` and related annotations.
* Use `#[CoverageIgnore]` as the native equivalent.

## Convert assertions

Expectations start with `Expect::that()`. They do not use methods on the test
class.

```php
// PHPUnit                                                // Greenlight
$this->assertSame('a', $value);                           Expect::that($value)->toBe('a');
$this->assertEquals($expected, $order);                   Expect::that($order)->toEqual($expected);
$this->fail('Reason');                                    Fail::because('Reason');
$this->assertInstanceOf(Response::class, $r);             Expect::that($r)->toBeInstanceOf(Response::class);
$this->assertCount(3, $items);                            Expect::that($items)->toHaveCount(3);
$this->expectException(DomainException::class);           Expect::that($fn)->toThrow(DomainException::class);
$this->assertEmpty($items);                               Expect::that($items)->toBeEmpty();
$this->assertGreaterThanOrEqual(3, $n);                   Expect::that($n)->toBeGreaterThanOrEqual(3);
$this->assertIsArray($value);                             Expect::that($value)->toBeArray();
$this->assertContains($needle, $haystack);                Expect::that($haystack)->toContain($needle);
$this->assertEqualsCanonicalizing($a, $b);                Expect::that($b)->toEqualCanonicalizing($a);
$this->assertJson($payload);                              Expect::that($payload)->toBeJson();
$this->assertJsonStringEqualsJsonString($e, $a);          Expect::that($a)->toMatchJson($e);
```

Other type predicates include `toBeString()`, `toBeInt()`, `toBeFloat()`,
`toBeBool()`, `toBeCallable()`, and `toBeIterable()`.

Membership matchers include `toBeOneOf()` and `toBeIn()`. Other matchers
include `toHaveLength()` and `toContainSubset()`.

See `Greenlight\Expect\Expectation` for the complete list.

These differences are important:

* `toEqual()` uses defined deep-equality rules.
* Integers and floats compare by numeric value.
* Other scalar values compare strictly.
* Arrays compare by keys and recursively equal values.
* Objects compare by exact class and all properties, with private properties.
* Unlike types do not use loose equality. Thus, `'1'` does not equal `1`.
* `->not()` applies only to the next matcher.
* `toThrow()` accepts a callable subject and an optional message constraint.
* Use `message:` for exact equality.
* Use `matching:` for a regular expression.
* Do not use `message:` and `matching:` in one call.
* `Fail::because()` replaces `$this->fail()` and supports explicit type guards.
* `Fail::because()` counts as an expectation and reports the guard location.
* A failed matcher throws immediately. Greenlight has no soft-assertion mode.

Replace manual `sleep()` calls or retry loops with `eventually()`:

```php
Expect::eventually(fn() => $repository->find($id))
    ->within(2.0)
    ->toEqual($expected);
```

Use `consistently()->for()` when a value must not change. A probe exception
stops the poll unless `retryOnException()` lists its type.

## Convert test doubles

Constructor injection supplies the `Doubles` service.

Greenlight does not create tolerant doubles. PHPUnit `createMock()` can create
a double whose methods return `null` or automatic stubs.

Greenlight has no equivalent object.

`mock(Type::class, fn (MockPlan $plan) => ...)` creates a strict mock.
Greenlight verifies each planned expectation at the end of the test.

An unplanned call fails the test immediately. Configure each return value with
`andReturns()`, `andReturnsSequence()`, `andReturnsUsing()`, or `andThrows()`.

Replace `willReturnOnConsecutiveCalls()` with `andReturnsSequence(...)`. The
sequence consumes one value for each call.

A call after the last value is a test-author error.

Replace `willReturnCallback()` with `andReturnsUsing(fn (...) => ...)`. The
callback receives the call arguments.

Replace argument constraints with `Greenlight\Doubles\Argument`:

```php
// PHPUnit                                          // Greenlight
$mock->method('save')->with($this->anything());     $plan->expects('save')->with(Argument::any());
$this->isInstanceOf(Order::class)                   Argument::type(Order::class)
$this->callback(fn ($v) => $v > 0)                  Argument::predicate(fn ($v) => $v > 0, 'positive')
$this->equalTo($expected)                           Argument::equals($expected)
```

Use a captured argument instead of callback inspection:

```php
$captor = $plan->expects('save')->once()->andReturns(true)->captureArgument(0);
// ... exercise the subject ...
Expect::that($captor->value())->toBeInstanceOf(Order::class);
```

`stub(Type::class)` supplies a collaborator and rejects each interaction.

If the collaborator must return a value, use a mock with explicit
expectations.

`spy(Type::class)` records calls only for methods that return nothing. A spy
does not create a return value.

Read records with `$this->doubles->callsTo($spy, 'method')`. Check the records
with `Expect`.

```php
$gateway = $this->doubles->mock(PaymentGateway::class, function (MockPlan $plan) use ($amount, $ok) {
    $plan->expects('charge')->with($amount)->once()->andReturns($ok);
});
```

Greenlight verifies mocks when the per-test scope closes. You do not need a
`Mockery::close()` equivalent.

Greenlight can double interfaces and non-final classes. It rejects final
classes, readonly classes, and enums.

The error recommends an interface. Greenlight does not support partial mocks
or static method mocks.

After migration, strict doubles can expose interactions that old tests
accepted.

See [test doubles](test-doubles.md) for the complete doubles API.

## Replace class fixtures

Replace `setUpBeforeClass()` and static fixture properties with per-class
harness services.

A per-class harness service is a typed object with `PerClass` scope. Greenlight
creates one instance for each test class.

Greenlight injects this instance into each test constructor. It disposes the
instance after the class completes.

Plugins register harness services. A plugin implements `HarnessProvider` and
returns service definitions with their scopes.

For shared suite fixtures, move the fixtures to a small plugin. Do not keep
them in a static property on the test class.

## Understand the deliberate differences

These differences are intentional:

* Greenlight has no `TestCase` base class.
* Tests declare dependencies in the constructor.
* The runner supplies the declared dependencies.
* Greenlight has no inherited assertion API or `parent::setUp()` chain.
* Greenlight has no test method name pattern.
* The `#[Test]` attribute identifies each test method.
* Greenlight has no `#[Depends]`.
* Test dependencies create hidden order requirements that conflict with
  parallel execution.
* Put expensive shared state in a class-scoped or suite-scoped harness service.
* Tests run in parallel worker processes by default.
* Use `#[Isolated]` for a test that must own its process.
* External dependencies require an explicit parallel strategy.
* Use a channel for one resource for each worker.
* Use `#[RequiresResource]` to limit access to a shared dependency.
* Doubles are strict.
* Unplanned interactions fail, and Greenlight does not invent return values.

## Migration sequence

1. Add `greenlight.php`.
2. Configure the test directories.
3. Convert one leaf test class manually.
4. Remove the base class.
5. Add `#[Test]` to each test method.
6. Convert assertions to `Expect::that()`.
7. Convert data providers.
8. Keep the provider body when only the attribute must change.
9. Convert mocks after the other test code.
10. Use strict-double failures to find loose assumptions in the old tests.
11. Run with `--workers=1` to exclude parallel execution from the first runs.
12. Remove `--workers=1`.
13. Correct failures that occur only with parallel workers.
