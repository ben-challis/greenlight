# Move from PHPUnit

Greenlight and PHPUnit use different test structures. A migration usually
changes the test support code, but much test logic can remain the same. A
bundled Rector rule automates the mechanical part of the change. This guide
describes the concepts behind the conversion.

## Convert tests automatically

Greenlight includes the `Greenlight\Rector\PhpUnitToGreenlightRector` rule for
[Rector](https://getrector.com) 2. Install Rector as a development dependency:

```console
composer require --dev rector/rector:^2.5
```

The rule rewrites final, attribute-based PHPUnit 10+ test classes. It converts
the `TestCase` parent, hooks, attributes, assertions, `expectException()`
blocks, `markTestSkipped()`, and `fail()`.

Register the rule in a `rector.php` file that selects your test directories:

```php
declare(strict_types=1);

use Greenlight\Rector\PhpUnitToGreenlightRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/tests'])
    ->withImportNames(removeUnusedImports: true)
    ->withRules([PhpUnitToGreenlightRector::class]);
```

The rule converts a final class only when each member has a faithful Greenlight
equivalent. All other classes remain valid PHPUnit code, so a suite can move in
steps. Converted classes run with Greenlight. The remaining classes continue
to run with PHPUnit.

A class does not convert when it uses:

* a non-final test class
* test doubles such as `createMock()`, which you convert manually to
  [strict doubles](test-doubles.md)
* `#[Depends]`, `setUpBeforeClass()`, `tearDownAfterClass()`, or traits
* assertions without a Greenlight matcher, for example file or XML assertions
* `assertEmpty()` or `assertNotEmpty()`, because Greenlight uses different
  empty-value rules
* multiple data providers or multiple requirements on one declaration
* `#[RunClassInSeparateProcess]` or `#[PreserveGlobalState]`
* other inherited `TestCase` API that the rule cannot prove safe

A custom failure message on an assertion has no Greenlight equivalent. By
default, a message prevents the conversion of the class. Use this
configuration to remove the messages:

```php
    ->withConfiguredRule(PhpUnitToGreenlightRector::class, [
        PhpUnitToGreenlightRector::DROP_ASSERTION_MESSAGES => true,
    ])
```

Two conversions change the code shape. An `expectException()` block becomes a
`toThrow()` expectation over an arrow function, and the earlier statements do
not move. `expectExceptionMessage()` finds a substring, so the rule writes a
quoted `matching:` pattern and not an exact `message:` constraint.

The rule preserves each repeated `#[TestWith]` row. It rejects class-process
and global-state options that have different Greenlight behavior.

Some attribute conversions are less direct:

| PHPUnit | Greenlight |
| --- | --- |
| `#[Ticket]` | `#[Group]` |
| `#[Small]`, `#[Medium]`, and `#[Large]` | `#[Group('small')]`, `#[Group('medium')]`, and `#[Group('large')]` |
| `#[RunInSeparateProcess]` or `#[RunTestsInSeparateProcesses]` | `#[Isolated]` |
| `#[DoesNotPerformAssertions]` | `#[NoExpectations]` |
| `#[RequiresPhpExtension]` | `#[SkipUnless]` with `ExtensionLoaded` |
| `#[RequiresOperatingSystemFamily]` | `#[SkipUnless]` with `OperatingSystemFamily` |

The rule removes coverage metadata attributes, for example `#[CoversClass]`,
because coverage configuration belongs in `greenlight.php`. It also removes
use metadata, `#[TestDox]`, and `#[DisableReturnValueGenerationForTestDoubles]`.

Rector's printer also reflows each converted class. Run your code-style fixer
after the conversion. Then run the suite one time with `--workers=1` before you
enable parallel workers.

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
$this->assertTrue($open, 'Order must stay open');         Expect::that($open)->because('Order must stay open')->toBeTrue();
$this->assertInstanceOf(Response::class, $r);             Expect::that($r)->toBeInstanceOf(Response::class);
$this->assertCount(3, $items);                            Expect::that($items)->toHaveCount(3);
$this->expectException(DomainException::class);           Expect::that($fn)->toThrow(DomainException::class);
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
* PHPUnit `assertEmpty()` uses PHP `empty()` semantics.
* Greenlight `toBeEmpty()` accepts strings, arrays, `Countable`, and iterables.
* Convert `assertEmpty()` and `assertNotEmpty()` manually.
* `->not()` applies only to the next matcher.
* `->because()` replaces the PHPUnit `$message` argument and applies only to
  the next matcher.
* `toThrow()` accepts a callable subject and an optional message constraint.
  * Use `message:` for exact equality.
  * Use `matching:` for a regular expression.
  * Pass a Throwable instance to require the exact object.
  * Use a typed throwable callback to check the caught throwable.
  * Do not use `message:` and `matching:` in one call.
* `Fail::because()` replaces `$this->fail()` and supports explicit type guards.
* `Fail::because()` counts as an expectation and reports the guard location.
* A failed matcher throws immediately. Greenlight has no soft-assertion mode.

A throwable callback removes manual exception capture. Its parameter type
specifies the expected throwable class. Its body can check the message, the
previous throwable, and other throwable state.

Replace this pattern:

```php
try {
    $fixtureManager->start();
    $this->fail('Expected the fixture manager to fail.');
} catch (IntegrationFixtureError $error) {
    $this->assertInstanceOf(
        LengthException::class,
        $error->getPrevious(),
    );
}
```

Use this expectation:

```php
Expect::that(fn() => $fixtureManager->start())
    ->toThrow(
        static function (IntegrationFixtureError $error): void {
            Expect::that($error->getPrevious())
                ->toBeInstanceOf(LengthException::class);
        },
    );
```

The callback runs only after the throwable type matches.

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

PHPUnit `createMock()` can create a tolerant double whose methods return `null`
or automatic stubs. Greenlight has no tolerant double equivalent.

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

Double targets are interfaces or non-final, non-readonly classes. Prefer an
interface because class doubles preserve final, static, and protected
implementations.

After migration, strict doubles can expose interactions that old tests
accepted.

See [test doubles](test-doubles.md) for the complete doubles API.

## Replace class fixtures

Replace `setUpBeforeClass()` and static fixture properties with per-class
harness services.

A per-class harness service is a typed object with `PerClass` scope. Greenlight
creates one instance for each test class.

External infrastructure such as database servers, message brokers, or
containers belongs in an `IntegrationFixtureProvider`. It provisions in the
orchestrator, can allocate one resource per worker channel, and tears down after
the run even if workers fail. Worker-side tests consume its serializable
connection data through `IntegrationResources` or a `HarnessProvider` bridge.

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
3. Run the bundled Rector rule across the suite.
4. Convert one remaining leaf test class manually.
5. Remove the base class.
6. Add `#[Test]` to each test method.
7. Convert assertions to `Expect::that()`.
8. Convert data providers.
9. Keep the provider body when only the attribute must change.
10. Convert mocks after the other test code.
11. Use strict-double failures to find loose assumptions in the old tests.
12. Run with `--workers=1` to exclude parallel execution from the first runs.
13. Remove `--workers=1`.
14. Correct failures that occur only with parallel workers.
