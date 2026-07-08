# Migrating from PHPUnit

This guide is about concepts, not automation.

Greenlight does some things differently from PHPUnit, so migration is usually a
rewrite of the test scaffolding rather than a line-by-line port. In many cases,
the body of the test can stay close to what it was.

## Concept mapping

| PHPUnit                                          | Greenlight                                               |
| ------------------------------------------------ | -------------------------------------------------------- |
| `extends TestCase`                               | nothing; tests are plain final classes                   |
| `testFoo()` naming                               | `#[Test]` on any public method                           |
| `setUp()`                                        | `#[Before]` on a public method                           |
| `tearDown()`                                     | `#[After]` on a public method                            |
| `#[DataProvider('cases')]`                       | `#[DataSet('cases')]`, static provider on the same class |
| `#[TestWith([1, 2])]`                            | `#[DataRow([1, 2])]`, optionally labelled                |
| `#[Group('slow')]` / `@group`                    | `#[Group('slow')]`, repeatable, method or class          |
| `$this->markTestSkipped($reason)`                | `throw new SkipTest($reason)` from `Greenlight\Plugin`   |
| `#[RequiresPhpExtension]` and related attributes | `#[SkipUnless(SomeCondition::class)]`                    |
| `$this->assert...()`                             | static `Expect::that(...)` chains                        |
| `createMock()` / `getMockBuilder()`              | injected `Doubles` service: `mock()`, `stub()`, `spy()`  |
| `setUpBeforeClass()` statics                     | per-class harness services                               |
| `#[RunInSeparateProcess]`                        | `#[Isolated]`                                            |
| `#[Depends]`                                     | no equivalent                                            |

## Assertions

Assertions start from `Expect::that()`, not from methods on the test class.

```php
// PHPUnit                                          // Greenlight
$this->assertSame('a', $value);                     Expect::that($value)->toBe('a');
$this->assertEquals($expected, $order);             Expect::that($order)->toEqual($expected);
$this->assertInstanceOf(Response::class, $r);       Expect::that($r)->toBeInstanceOf(Response::class);
$this->assertCount(3, $items);                      Expect::that($items)->toHaveCount(3);
$this->expectException(DomainException::class);     Expect::that($fn)->toThrow(DomainException::class);
```

A few differences matter during migration:

* `toEqual()` uses deep equality with defined rules. Integers and floats compare
  by numeric value. Other scalars compare strictly. Arrays compare by keys and
  recursively equal values. Objects compare by exact class and all properties,
  including private properties. There is no loose `assertEquals()`-style
  comparison between unlike types, so `'1'` does not equal `1`.
* Negation is a chain step, such as `->not()->toContain($x)`, and applies only
  to the next matcher.
* `toThrow()` takes a callable subject and an optional message pattern. It
  replaces the usual `expectException*` setup calls with one expression.
* Expectations fail fast. A failed matcher throws immediately. There is no
  soft-assertion mode.

## Test doubles

Doubles come from an injected `Doubles` service.

The main difference from PHPUnit is that Greenlight does not guess behaviour.
PHPUnit's `createMock()` can create a tolerant dummy where methods silently
return `null` or auto-stubs. Greenlight has no equivalent object.

`mock(Type::class, fn (MockPlan $plan) => ...)` is strict. Every planned
expectation is verified when the test ends, and any call that matches no planned
expectation fails the test immediately. Return values must be configured
explicitly with `andReturns()` or `andThrows()`.

`stub(Type::class)` fills a collaborator slot and fails the test on any
interaction. If the collaborator needs to return something, use a mock with
explicit expectations instead.

`spy(Type::class)` records calls, but only for methods that return nothing. A
spy never invents a return value. Read recordings with
`$this->doubles->callsTo($spy, 'method')` and assert on them with `Expect`.

```php
$gateway = $this->doubles->mock(PaymentGateway::class, function (MockPlan $plan) use ($amount, $ok) {
    $plan->expects('charge')->with($amount)->once()->andReturns($ok);
});
```

Mocks are verified automatically when the per-test scope closes. There is no
`Mockery::close()` equivalent to remember.

Interfaces and non-final classes can be doubled. Final classes, readonly
classes, and enums are rejected with a suggestion to double an interface instead.

There are no partial mocks and no static method mocks.

Tests that relied on tolerant PHPUnit mocks may fail at first after migration.
Those failures usually point to interactions the old test allowed implicitly.

## Class-level fixtures

`setUpBeforeClass()` and static fixture properties map to per-class harness
services.

A per-class harness service is a typed object with `PerClass` scope. It is
constructed once for the class, injected into each test constructor, and disposed
when the class finishes.

Harness services are registered by plugins. A plugin implements
`HarnessProvider` and returns service definitions with their scopes. If a test
suite has shared fixtures, expect to move them into a small plugin rather than a
static property on the test class.

## Deliberate differences

These are intentional differences, not missing PHPUnit features.

* There is no `TestCase` base class. Tests declare their dependencies in the
  constructor, and the runner provides them. There is no inherited assertion API
  and no `parent::setUp()` chain.
* There is no test method naming convention. `#[Test]` marks tests explicitly.
* There is no `#[Depends]`. Test dependencies create hidden ordering contracts
  that do not work well with parallel execution. Expensive shared state belongs
  in a class- or suite-scoped harness service.
* Tests run in parallel by default, across worker processes sized to the machine.
  Tests that assume they own the process need `#[Isolated]` or a design change.
* Doubles are strict. Unplanned interactions fail, and no return value is guessed.

## Practical order of attack

1. Add `greenlight.php` and point it at your test directories.
2. Port one leaf test class by hand. Remove the base class, add `#[Test]`, and
   convert assertions to `Expect::that()`.
3. Convert data providers. The provider body usually stays the same; only the
   attribute changes.
4. Convert mocks last. Strict doubles will expose loose assumptions in the old
   tests.
5. Run with `--workers=1` first to remove parallelism from the migration. Then
   remove the flag and fix anything that only fails when tests run in parallel.
