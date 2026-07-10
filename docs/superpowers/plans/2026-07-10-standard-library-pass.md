# Standard Library Pass Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fill first-party convenience gaps around Greenlight's existing primitives: matchers, conditions, CLI selection, doubles ergonomics, harness fixtures, and flake-finding repeats.

**Architecture:** Every addition builds on an existing extension point: matchers go through `Expectation::verify()`, conditions through `#[SkipUnless]` (extended to carry scalar arguments), CLI exclusions through the already-complete `Discovery\Filter`, doubles matchers through a new `ArgumentMatcher` interface generalising the existing `Any` marker, fixtures through `ServiceDefinition` registration in `DefaultServices`, and `--repeat` through the fresh-reporter-per-iteration pattern watch mode already uses.

**Tech Stack:** PHP 8.4, self-hosted test suite (`php bin/greenlight run`), phpstan + deptrac + php-cs-fixer + rector gates.

---

## Assessment of what already exists

- **Matchers:** 16 native matchers on `src/Expect/Expectation.php`, all funnelled through private `verify()` (message + counter + negation) and `usageFailure()` (subject-type misuse, never invertible). Native methods need no PhpStan/IDE registration; that machinery only serves third-party `__call` matchers. `Equality::equals()` already ignores assoc key order but not list order; no subset or canonicalizing comparison exists.
- **Conditions:** `Greenlight\Core\Condition` interface exists with zero built-in implementations. `#[SkipUnless]` carries only a class-string; `TestMetadata` and `TestExecutor::evaluateCondition()` (`new $class()`) assume no constructor arguments. Parameterised conditions need all three touched, plus wire serialisation.
- **CLI selection:** `Discovery\Filter` already implements excludeGroups/excludeClasses/excludeMethods/excludePaths (exclude always wins), but nothing populates them. A `list-tests` command exists (groups + id filters + shard honoured). Suites live in `Configuration::$suites` (name, paths, tags). Canonical pipeline order is filter, then shard, then order, inside both runners.
- **Doubles:** `MethodExpectation` stores a single return value; `Any` is a bare marker special-cased via `instanceof` in `matchesArguments()`. Every call's arguments are already recorded in `DoubleState::$recordedCalls`. Double failures already read as assertion failures (`ExpectationFailed` + `FailureDetail`).
- **Harness fixtures:** Registration (`ServiceDefinition`, `Scope`), disposal (`Disposable`, reverse order, exception-safe), and constructor injection all work; built-ins are just `Doubles` and `TestChannel`. No clock abstraction exists (PSR-20 not a dependency). Scopes are per worker process; `TestChannel` disambiguates parallel workers.
- **Repeat:** No repeat support. `Reporter::finish()` is contractually once per reporter; `watchCommand()`'s `$runOnce` closure (fresh reporter + fresh `FailedTestsTap` per iteration) is the template.

## Design decisions (made in this pass)

1. **Membership semantics:** `toBeOneOf(...$options)` and `toBeIn($haystack)` use identity (`===`), consistent with `toContain`. Documented on the methods.
2. **`toHaveLength`:** strings count UTF-8 code points (`preg_match_all('/./us')`, byte length fallback for invalid UTF-8); arrays and `Countable` use `count()`.
3. **`toBeEmpty`:** accepts string, array, `Countable`, `iterable`; empty string or zero elements. Other subjects are usage failures (no PHP `empty()` semantics).
4. **JSON matchers:** subject must be a string; `json_validate()` gates validity. `toMatchJson()` reports invalid subject JSON distinctly from structural mismatch, and invalid expected JSON is a usage failure. Comparison decodes to arrays and uses `Equality::equals` (key order insensitive by construction).
5. **Canonicalizing equality:** new `Equality::equalsCanonicalizing()` recursively sorts list values (assoc keys already order-insensitive) before comparing.
6. **Parameterised conditions:** `#[SkipUnless(PhpVersionAtLeast::class, '8.5')]`. The attribute gains variadic scalar arguments; `TestMetadata` carries them over the wire; `TestExecutor` constructs `new $class(...$args)`. Non-scalar arguments are a `DiscoveryError`. Skip reason renders class + arguments.
7. **Conditions namespace:** `src/Condition/` (`Greenlight\Condition\...`), new deptrac layer `Condition: [Core]`.
8. **Fixtures namespace:** `src/Fixture/` (`Greenlight\Fixture\...`), deptrac layer `Fixture: [Core, Harness]`; `Runner` gains `Fixture`. All four registered `Scope::PerTest` in `DefaultServices`.
9. **Listing:** `--list-tests`, `--list-groups`, `--list-suites` are flags on the `run` command (precedent: `--dry-run` prints and exits 0). The existing `list-tests` command stays and gains exclude-filter support. Output sorted for determinism.
10. **Doubles matching:** new `ArgumentMatcher` interface (`matches(mixed): bool`, `describe(): string`); `Any` implements it. `Argument` provides `any()`, `type()`, `predicate()`, `equals()`, `captor()`. `MethodExpectation::captureArgument(int $position = 0): ArgumentCaptor` records the argument of every matched call.
11. **Sequences:** `andReturnsSequence(...)` consumes one value per matched call; exhaustion is a `DoublesError` (authoring error, loud). Conflicting answer configuration (`andReturns` after `andReturnsSequence`, etc.) is a `DoublesError`.
12. **Repeat:** `--repeat=N` runs the plan N times; `--repeat-until-failure` stops at first failing iteration, bounded by `--repeat` if given, else a 100-iteration safety limit. Fresh reporter and tap per iteration; iteration banner printed between runs; exit code fails if any iteration failed. Incompatible with `--watch` (CLI error).
13. **Clocks:** no PSR-20 dependency added; `FrozenClock`/`MutableClock` expose `now(): \DateTimeImmutable` directly. `FrozenClock` freezes at construction; `MutableClock` supports `set()` and `advance()`.

---

## Task A: Matcher expansion

**Files:**
- Modify: `src/Expect/Expectation.php` (add 17 methods after existing matchers)
- Modify: `src/Expect/Equality.php` (add `equalsCanonicalizing()`)
- Create: `src/Expect/ArraySubset.php` (subset comparison + first-difference description, `@internal`)
- Test: `tests/Unit/Expect/NumericMatchersTest.php`, `TypeMatchersTest.php`, `IterableMatchersTest.php`, `StringMatchersTest.php`, new `JsonMatchersTest.php`, new `CanonicalizingTest.php`, new `SubsetMatchersTest.php`

Matchers: `toBeGreaterThanOrEqual(int|float)`, `toBeLessThanOrEqual(int|float)` (clone the `toBeGreaterThan` guard/verify shape); `toBeEmpty()`; `toBeOneOf(mixed ...$options)`; `toBeIn(iterable $haystack)`; `toBeArray()`, `toBeString()`, `toBeInt()`, `toBeFloat()`, `toBeBool()`, `toBeCallable()`, `toBeIterable()` (each `verify(is_*(...), 'to be ...', '<type>', get_debug_type($subject))`); `toHaveLength(int $length)`; `toEqualCanonicalizing(mixed $expected)`; `toContainSubset(array $subset)`; `toBeJson()`; `toMatchJson(string $expected)`.

Every matcher: pass test, failure-message test (message + expected via `FailureProbe::detailOf`), `not()` test, and usage-guard test where a guard exists. TDD per matcher family, commit per family.

## Task B: Stock condition library

**Files:**
- Create: `src/Condition/{ExtensionLoaded,ExtensionMissing,EnvironmentVariableSet,EnvironmentVariableEquals,OperatingSystemFamily,PhpVersionAtLeast,PhpVersionLessThan,FunctionAvailable,ClassAvailable}.php`
- Modify: `src/Attribute/SkipUnless.php` (add `mixed ...$arguments`, stored as `public array $arguments`)
- Modify: `src/Core/Test/TestMetadata.php` (add `skipUnlessArguments`, wire round-trip)
- Modify: `src/Discovery/MetadataFactory.php` (pass arguments through; non-scalar argument -> `DiscoveryError`)
- Modify: `src/Runner/Worker/TestExecutor.php` (`new $class(...$args)`; reason renders `Condition ExtensionLoaded("redis") is not satisfied.`)
- Modify: `src/Core/Condition.php` docblock (constructor arguments now allowed via `#[SkipUnless]`)
- Modify: `deptrac.yaml` (layer `Condition: [Core]`)
- Test: `tests/Unit/Condition/ConditionsTest.php`, extend `tests/Unit/Core/TestMetadataTest.php`, `tests/Unit/Discovery/AttributeMergeTest.php`, `tests/Unit/Runner/WorkerTest.php` fixture coverage for a parameterised condition

Conditions evaluate lazily in `isSatisfied()` (constructor stores arguments only, no side effects). `OperatingSystemFamily` compares against `PHP_OS_FAMILY` case-insensitively. Version conditions use `version_compare` against `PHP_VERSION`.

## Task C: CLI selection flags and repeat

**Files:**
- Modify: `src/Cli/Application.php` (optionSpecs, HELP text, list flags handling, repeat loop, `listTestsCommand` excludes)
- Modify: `src/Cli/CliOverrides.php` (parse/validate new options)
- Modify: `src/Config/Configuration.php` (+ excludeGroups/excludeClasses/excludeMethods/excludePaths fields)
- Modify: `src/Cli/ConfigurationResolver.php` (thread excludes)
- Modify: `src/Runner/InProcessRunner.php`, `src/Runner/ParallelRunner.php` (pass excludes into `new Filter(...)`)
- Test: `tests/Acceptance/SelectionTest.php` (excludes + listing), `tests/Acceptance/RepeatTest.php`, `tests/Unit/Cli/CliOverridesTest.php`

Flags: `--exclude-group`, `--exclude-class`, `--exclude-method`, `--exclude-path` (all `OptionValue::Required, repeatable: true`); `--list-tests`, `--list-groups`, `--list-suites` (flags, print sorted and exit 0); `--repeat=N` (int >= 2); `--repeat-until-failure`. Repeat loop wraps the runner-dispatch block with fresh reporter + tap per iteration (per watch's `$runOnce`), prints `Repeat: iteration i of N`, records which iterations failed, and exits non-zero if any did.

## Task D: Doubles ergonomics

**Files:**
- Create: `src/Doubles/ArgumentMatcher.php`, `src/Doubles/Argument.php`, `src/Doubles/ArgumentCaptor.php`, `src/Doubles/TypeMatcher.php`, `src/Doubles/PredicateMatcher.php`, `src/Doubles/EqualsMatcher.php` (or a single file per matcher as fits house style)
- Modify: `src/Doubles/Any.php` (implement `ArgumentMatcher`)
- Modify: `src/Doubles/MethodExpectation.php` (answer strategies, matcher-aware `matchesArguments`/`describeCall`, `captureArgument()`)
- Modify: `src/Doubles/CallHandler.php` (record matched args into captors, answer via strategy)
- Modify: `src/Doubles/DoublesError.php` (sequence exhausted, conflicting answers)
- Test: `tests/Unit/Doubles/MockTest.php` extensions, new `tests/Unit/Doubles/ArgumentMatchingTest.php`, `tests/Unit/Doubles/AnswersTest.php`

`Argument::type()` accepts class-strings (instanceof) and builtin names matching `get_debug_type`. Unmet/unexpected interactions keep flowing through `FailureDetail`/`ExpectationFailed`.

## Task E: Harness fixtures

**Files:**
- Create: `src/Fixture/TempDirectory.php`, `src/Fixture/EnvironmentSandbox.php`, `src/Fixture/FrozenClock.php`, `src/Fixture/MutableClock.php`
- Modify: `src/Runner/DefaultServices.php` (register all four `Scope::PerTest`)
- Modify: `deptrac.yaml` (layer `Fixture: [Core, Harness]`; `Runner` += `Fixture`)
- Test: `tests/Unit/Fixture/*Test.php`, plus a lifecycle fixture proving disposal restores env/removes dirs

`TempDirectory`: lazily-created unique dir under `sys_get_temp_dir()` (`greenlight-` prefix + random suffix, per process so parallel-safe); `path()`, `subdirectory()`; recursive removal on dispose. `EnvironmentSandbox`: `set()`, `unset()`; records originals once (`getenv`/`$_ENV`/`$_SERVER` kept in sync); restores on dispose. `FrozenClock`: `now()` fixed at construction, `::at()` named constructor. `MutableClock`: `now()`, `set()`, `advance()` accepting `\DateInterval` or ISO 8601 duration string or seconds.

## Task F: Documentation

**Files:**
- Modify: `README.md` (feature bullets, doubles snippet)
- Modify: `docs/getting-started.md` (matcher families, fixture injection example)
- Modify: `docs/attributes.md` (`SkipUnless` arguments + built-in condition table)
- Modify: `docs/migrating-from-phpunit.md` (assertion mapping additions, doubles equivalents)
- Modify: `docs/configuration.md` or CLI section (exclude/list/repeat flags)

## Verification (final)

- `php bin/greenlight run` (full suite green)
- `composer static-analysis` (validate, lint, cs, phpstan, rector, deptrac)
