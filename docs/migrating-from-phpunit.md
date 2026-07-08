# Migrating from PHPUnit

This is a conceptual guide, not an automated migration. Greenlight deliberately does some things differently, so a port is a rewrite of each test's scaffolding around a usually unchanged body. The mappings below tell you where each PHPUnit concept lands.

## Concept mapping

| PHPUnit | Greenlight |
| --- | --- |
| `extends TestCase` | nothing; tests are plain final classes |
| `testFoo()` naming | `#[Test]` on any public method |
| `setUp()` | `#[Before]` on a public method |
| `tearDown()` | `#[After]` on a public method |
| `#[DataProvider('cases')]` | `#[DataSet('cases')]`, static provider on the same class |
| `#[TestWith([1, 2])]` | `#[DataRow([1, 2])]`, optionally labelled |
| `#[Group('slow')]` / `@group` | `#[Group('slow')]`, repeatable, method or class |
| `$this->markTestSkipped($reason)` | `throw new SkipTest($reason)` from `Greenlight\Plugin` |
| `#[RequiresPhpExtension]` and friends | `#[SkipUnless(SomeCondition::class)]` |
| `$this->assert...()` | static `Expect::that(...)` chains |
| `createMock()` / `getMockBuilder()` | injected `Doubles` service: `mock()`, `stub()`, `spy()` |
| `setUpBeforeClass()` statics | per-class harness services |
| `#[RunInSeparateProcess]` | `#[Isolated]` |
| `#[Depends]` | no equivalent, on purpose |

## Assertions

Assertions start from the static `Expect::that()`, not from methods on the test class. Five representative pairs:

```php
// PHPUnit                                          // Greenlight
$this->assertSame('a', $value);                     Expect::that($value)->toBe('a');
$this->assertEquals($expected, $order);             Expect::that($order)->toEqual($expected);
$this->assertInstanceOf(Response::class, $r);       Expect::that($r)->toBeInstanceOf(Response::class);
$this->assertCount(3, $items);                      Expect::that($items)->toHaveCount(3);
$this->expectException(DomainException::class);     Expect::that($fn)->toThrow(DomainException::class);
```

Differences worth knowing:

- `toEqual()` is deep equality with documented semantics: ints and floats compare by numeric value, other scalars strictly, arrays by keys and recursively equal values, objects by exact class and every property including private ones. There is no `assertEquals`-style loose comparison of unlike types; `'1'` does not equal `1`.
- Negation is a chain step, `->not()->toContain($x)`, and applies to the next matcher only.
- `toThrow()` takes a callable subject and an optional message pattern, replacing the four `expectException*` calls with one expression.
- Every expectation fails fast: a failed matcher throws immediately. There is no soft-assertion mode.

## Test doubles

Doubles come from an injected `Doubles` service. The honest difference from PHPUnit: nothing is ever guessed. PHPUnit's `createMock()` returns a tolerant dummy where every method silently returns null or an auto-stub. Greenlight has no such object.

- `mock(Type::class, fn (MockPlan $plan) => ...)` is strict. Every expectation you plan is verified when the test ends, and any call matching no planned expectation fails the test immediately. Every return value must be configured explicitly with `andReturns()` or `andThrows()`.
- `stub(Type::class)` satisfies a type so a collaborator slot can be filled, and errors the test on any interaction at all. If the test needs the collaborator to answer, that is a mock with explicit expectations.
- `spy(Type::class)` records calls, but only methods that return nothing can be spied on; a spy never invents a return value. Read recordings back with `$this->doubles->callsTo($spy, 'method')` and assert on them with Expect.

```php
$gateway = $this->doubles->mock(PaymentGateway::class, function (MockPlan $plan) use ($amount, $ok) {
    $plan->expects('charge')->with($amount)->once()->andReturns($ok);
});
```

Mocks verify automatically when the per-test scope closes; there is no `Mockery::close()` equivalent to forget. Interfaces and non-final classes can be doubled; final classes, readonly classes, and enums are rejected with a suggestion to double an interface. There are no partial mocks and no static method mocking. Expect migrated tests that leaned on tolerant PHPUnit mocks to fail loudly at first; each failure is an interaction the old test silently ignored.

## Class-level fixtures

`setUpBeforeClass()` with static properties maps to per-class harness services: typed objects with a declared `PerClass` scope that are constructed once for the class, injected into each test's constructor, and disposed when the class finishes. Registering harness services is plugin territory (a plugin implements `HarnessProvider` and declares services with a scope), so if you maintain shared fixtures you will be writing a small plugin rather than a static property.

## Deliberate differences

These are not gaps to be filled; they are the design.

- No `TestCase` base class. Tests declare what they need in the constructor and the runner provides it. There is no inherited 300-method API and no `parent::setUp()` chain to get wrong.
- No test method name convention. `#[Test]` is explicit and greppable.
- No `#[Depends]`. Inter-test dependencies create hidden ordering contracts that break under parallelism. Expensive shared state belongs in a class- or suite-scoped harness service.
- Parallel by default. Tests run across worker processes sized to your CPU count. Tests that assume they own the process need `#[Isolated]` or fixing.
- Strict doubles. Unplanned interactions fail; nothing returns a guessed value.

## Practical order of attack

1. Add `greenlight.php` pointing at your test directories.
2. Port one leaf test class by hand: drop the base class, add `#[Test]`, convert assertions to `Expect::that()`.
3. Convert data providers; the provider body usually survives untouched, only the attribute changes.
4. Convert mocks last, and budget time for them: strictness will surface real looseness in the old tests.
5. Run with `--workers=1` first to take parallelism out of the picture, then remove the flag and fix anything that only fails in parallel.
