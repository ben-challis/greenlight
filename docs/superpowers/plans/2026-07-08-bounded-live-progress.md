# Bounded Live Progress Output Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Interactive runs show a bounded live window (counter + in-flight classes) instead of appending one line per passing class; non-interactive output stays append-only and escape-free. Implements docs/rfcs/RFC-005-bounded-live-progress.md.

**Architecture:** A pure `TerminalCapabilities::detect()` in `src/Cli/` decides interactivity and colour from isatty/env/flags. `TtyReporter` splits its `ansi` flag into `colour` (owned by `Style`) and `cursor` (owns the live region), suppresses permanent lines for cleanly passing classes unless `--verbose`, and renders a capacity-clamped window with a progress counter. `SummaryFormat::skipped()` groups shared skip reasons.

**Tech Stack:** PHP 8.4, greenlight's own test runner (`./bin/greenlight --filter=<pattern>`), phpstan, deptrac, php-cs-fixer, rector.

**Conventions (from CLAUDE.md/memory):** no PHPDoc tag alignment, no em-dashes in prose, `@internal` on new classes, class docblocks = summary sentence then one-concern paragraphs naming methods. All existing events carry a public `float $occurredAt`.

---

### Task 1: TerminalCapabilities

**Files:**
- Create: `src/Cli/TerminalCapabilities.php`
- Test: `tests/Unit/Cli/TerminalCapabilitiesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\TerminalCapabilities;
use Greenlight\Expect\Expect;

final class TerminalCapabilitiesTest
{
    #[Test]
    public function aPlainTtyIsInteractiveWithColour(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: [], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->toBeTrue()
            ->and($capabilities->colour)->toBeTrue();
    }

    #[Test]
    public function nonTtyIsNeverInteractive(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: false, env: [], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->toBeFalse()
            ->and($capabilities->colour)->toBeFalse();
    }

    #[Test]
    public function theNoAnsiFlagForcesNonInteractive(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: [], noAnsiFlag: true);

        Expect::that($capabilities->interactive)->toBeFalse()
            ->and($capabilities->colour)->toBeFalse();
    }

    #[Test]
    public function truthyCiForcesNonInteractiveEvenWithATty(): void
    {
        foreach (['true', '1', 'yes'] as $value) {
            $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['CI' => $value], noAnsiFlag: false);

            Expect::that($capabilities->interactive)->toBeFalse();
        }
    }

    #[Test]
    public function falsyCiValuesDoNotDisableInteractivity(): void
    {
        foreach (['', '0', 'false', 'FALSE', false] as $value) {
            $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['CI' => $value], noAnsiFlag: false);

            Expect::that($capabilities->interactive)->toBeTrue();
        }
    }

    #[Test]
    public function noColorStripsColourButKeepsInteractivity(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['NO_COLOR' => '1'], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->toBeTrue()
            ->and($capabilities->colour)->toBeFalse();
    }

    #[Test]
    public function anEmptyNoColorIsIgnored(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['NO_COLOR' => ''], noAnsiFlag: false);

        Expect::that($capabilities->colour)->toBeTrue();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./bin/greenlight --filter=TerminalCapabilitiesTest`
Expected: every test ERRORs with `Class "Greenlight\Cli\TerminalCapabilities" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Cli/TerminalCapabilities.php`:

```php
<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * What the output stream can do, decided once per invocation.
 *
 * detect() derives the two capabilities from the TTY check, an environment
 * snapshot, and the --no-ansi flag: interactive (live window and cursor
 * control) requires a TTY without --no-ansi and without a truthy CI
 * variable; colour additionally requires NO_COLOR to be unset or empty.
 * Detection is a pure function of its inputs so the whole matrix is
 * unit-testable.
 *
 * @internal
 */
final readonly class TerminalCapabilities
{
    public function __construct(
        public bool $interactive,
        public bool $colour,
    ) {}

    /**
     * @param array<string, string|false> $env getenv() snapshot for CI and NO_COLOR
     */
    public static function detect(bool $stdoutIsTty, array $env, bool $noAnsiFlag): self
    {
        $interactive = $stdoutIsTty && !$noAnsiFlag && !self::truthy($env['CI'] ?? false);
        $noColor = ($env['NO_COLOR'] ?? false) !== false && ($env['NO_COLOR'] ?? '') !== '';

        return new self($interactive, $interactive && !$noColor);
    }

    private static function truthy(string|false $value): bool
    {
        return $value !== false && $value !== '' && !\in_array(\strtolower($value), ['0', 'false'], true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./bin/greenlight --filter=TerminalCapabilitiesTest`
Expected: `7 tests, 7 passed`.

- [ ] **Step 5: Commit**

```bash
git add src/Cli/TerminalCapabilities.php tests/Unit/Cli/TerminalCapabilitiesTest.php
git commit -m "feat: terminal capability detection for output mode selection

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Skip grouping in SummaryFormat

**Files:**
- Modify: `src/Reporting/SummaryFormat.php` (the `skipped()` method and the class docblock paragraph describing it)
- Test: `tests/Unit/Reporting/SummaryFormatTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Reporting/SummaryFormatTest.php`:

```php
<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Style;
use Greenlight\Reporting\SummaryFormat;

final class SummaryFormatTest
{
    #[Test]
    public function aSingleTestPerReasonStaysInline(): void
    {
        $block = SummaryFormat::skipped([
            $this->skip('App\AlphaTest::one', 'needs redis'),
            $this->skip('App\BetaTest::two', null),
        ], new Style(ansi: false));

        Expect::that($block)->toBe(
            "\nSkipped:\n"
            . "  App\AlphaTest::one (needs redis)\n"
            . "  App\BetaTest::two (no reason given)\n",
        );
    }

    #[Test]
    public function sharedReasonsGroupWithACap(): void
    {
        $results = [];

        for ($i = 1; $i <= 7; ++$i) {
            $results[] = $this->skip(\sprintf('App\GammaTest::case%d', $i), 'xdebug not loaded');
        }

        $block = SummaryFormat::skipped($results, new Style(ansi: false));

        Expect::that($block)->toContain("  xdebug not loaded:\n    App\GammaTest::case1\n")
            ->and($block)->toContain("    App\GammaTest::case5\n")
            ->and($block)->not()->toContain('case6')
            ->and($block)->toContain('    … and 2 more');
    }

    /**
     * @param non-empty-string $id
     */
    private function skip(string $id, ?string $reason): TestResult
    {
        [$class, $method] = \explode('::', $id);
        \assert($class !== '' && $method !== '');

        return new TestResult(new TestId($class, $method), Outcome::Skipped, 0.0, 0, skipReason: $reason);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./bin/greenlight --filter=SummaryFormatTest`
Expected: `sharedReasonsGroupWithACap` FAILs (current code prints one flat line per test); the inline test passes because two distinct reasons keep the existing format.

- [ ] **Step 3: Implement grouping**

In `src/Reporting/SummaryFormat.php`, replace the `skipped()` method body:

```php
    /**
     * @param list<TestResult> $skipped
     */
    public static function skipped(array $skipped, Style $style): string
    {
        if ($skipped === []) {
            return '';
        }

        $groups = [];

        foreach ($skipped as $result) {
            $groups[$result->skipReason ?? 'no reason given'][] = $result;
        }

        $lines = ["\n" . $style->skip('Skipped:')];

        foreach ($groups as $reason => $results) {
            if (\count($results) === 1) {
                $lines[] = \sprintf('  %s (%s)', $results[0]->id, (string) $reason);

                continue;
            }

            $lines[] = \sprintf('  %s:', (string) $reason);

            foreach (\array_slice($results, 0, 5) as $result) {
                $lines[] = '    ' . $result->id;
            }

            if (\count($results) > 5) {
                $lines[] = \sprintf('    … and %d more', \count($results) - 5);
            }
        }

        return \implode("\n", $lines) . "\n";
    }
```

Note: the `(string) $reason` casts matter; PHP silently converts numeric-string array keys to ints.

Update the class docblock's skipped() sentence to:

```
 * skipped() lists every skipped test with its reason, grouping tests that
 * share a reason and capping each group at five ids, so a skip is never
 * just a number.
```

- [ ] **Step 4: Run tests to verify they pass, including the reporter goldens that call skipped()**

Run: `./bin/greenlight --filter=SummaryFormatTest && ./bin/greenlight --filter=Reporting`
Expected: all pass. The `PlainReporterTest` golden has a single-skip run, whose inline format is unchanged.

- [ ] **Step 5: Commit**

```bash
git add src/Reporting/SummaryFormat.php tests/Unit/Reporting/SummaryFormatTest.php
git commit -m "feat: group shared skip reasons in the run summary

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: TtyReporter mode plumbing (colour/cursor split, verbose, notable-only permanence)

`TtyReporter` currently takes `bool $ansi`. Split it into `colour` (feeds `Style`) and `cursor` (gates the live region), add `verbose`, and stop printing permanent lines for cleanly passing classes in bounded mode.

**Files:**
- Modify: `src/Reporting/TtyReporter.php`
- Modify: `tests/Unit/Reporting/TtyReporterTest.php`
- Modify: `src/Cli/Application.php:582` (the `'tty' =>` arm; keep it compiling with positional args, full wiring lands in Task 5)

- [ ] **Step 1: Update existing tests and add the new ones**

In `tests/Unit/Reporting/TtyReporterTest.php`:

1. Replace every `ansi: true` constructor argument with `colour: true, cursor: true`, and every `ansi: false` with `colour: false, cursor: false`.
2. In `interleavedClassesFinalizeInPlaceWithAnsi`, the passing class no longer prints a permanent line. Replace the assertion block with:

```php
        // The live region is erased and redrawn: cursor-up plus clear-to-end.
        Expect::that($buffer)->toContain("\x1b[2A\r\x1b[0J")
            ->and($buffer)->toContain('Greenlight dev-main | PHP 8.4.0 | config: greenlight.php | seed: 4242 | workers: 2')
            // Only the failing class earns a permanent line; the pass just counts.
            ->and($buffer)->not()->toContain('✓ App\AlphaTest')
            ->and($buffer)->toContain("\x1b[31m✗\x1b[0m App\BetaTest (1 test, 1 failed, 0.010s)")
            ->and($buffer)->toContain("2 tests, \x1b[32m1 passed\x1b[0m, \x1b[31m1 failed\x1b[0m, 0 expectations");
```

   (Delete the trailing `App\BetaTest (1)` live-line assertion for now; Task 4 changes the live-line format and adds its own assertions.)
3. In `slowDurationsAreColouredOnClassLines`, construct the reporter with `new TtyReporter($output, colour: true, cursor: true, verbose: true);` so the cleanly passing class still prints its final line.
4. Add two new tests:

```php
    #[Test]
    public function verboseRestoresAPermanentLinePerClass(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: true, cursor: true, verbose: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->finish();

        Expect::that($output->buffer())->toContain("\x1b[32m✓\x1b[0m App\AlphaTest (1 test, 0.010s)");
    }

    #[Test]
    public function withoutCursorEveryClassStillAppendsALine(): void
    {
        // --reporter=tty on a non-TTY degrades to append-only output; nothing
        // may become invisible just because the live window is unavailable.
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: false);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->finish();

        Expect::that($output->buffer())->toContain("✓ App\AlphaTest (1 test, 0.010s)\n")
            ->and($output->buffer())->not()->toContain("\x1b[");
    }
```

Note the existing `withoutAnsiOnlyFinalizedLinesAreWritten`, `zeroResultCategoriesAreOmittedFromTheSummary`, `skippedTestsAreUnambiguousAndListedWithReasons`, and `workersLineOmitsZeroRecycledAndDisappearsWhenNoneSpawned` tests keep passing untouched apart from the constructor-argument rename: they run with `cursor: false`, which is the append-everything path.

- [ ] **Step 2: Run tests to verify the new/changed ones fail**

Run: `./bin/greenlight --filter=TtyReporterTest`
Expected: ERRORs (unknown named argument `colour`) until the constructor changes; after re-checking, at minimum `interleavedClassesFinalizeInPlaceWithAnsi` and `verboseRestoresAPermanentLinePerClass` must FAIL against the old behaviour.

- [ ] **Step 3: Implement in `src/Reporting/TtyReporter.php`**

Constructor and fields:

```php
    public function __construct(
        private readonly Output\Output $output,
        bool $colour,
        private readonly bool $cursor,
        private readonly ?RunHeader $header = null,
        bool $extendedSlowTests = false,
        private readonly bool $verbose = false,
    ) {
        $this->style = new Style($colour);
        $this->slowTests = new SlowTests($extendedSlowTests);
    }
```

Replace every remaining `$this->ansi` with `$this->cursor` (`redraw()` and `eraseLiveRegion()` guards). In `finalizeClass()`, wrap the write:

```php
        $this->eraseLiveRegion();

        if ($this->verbose || !$this->cursor || $state['failed'] > 0 || $state['skipped'] > 0) {
            $this->output->write($this->finalLine($class, $state) . "\n");
        }

        $this->redraw();
```

Update the class docblock: replace the paragraph about one summary line per class with:

```
 * In bounded mode a cleanly passing class prints nothing permanent; only
 * classes containing failures or skips append a line, the moment they
 * finish. verbose restores a permanent line per class. Without cursor
 * support the live region is skipped and every class appends its line, so
 * output degrades to append-only rather than losing information.
```

In `src/Cli/Application.php` update the construction so the build stays green (final wiring in Task 5):

```php
                'tty' => new TtyReporter($output, $ansi, $ansi, $header, extendedSlowTests: $profile),
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./bin/greenlight --filter=TtyReporterTest && composer phpstan`
Expected: all TtyReporterTest tests pass; phpstan clean.

- [ ] **Step 5: Commit**

```bash
git add src/Reporting/TtyReporter.php tests/Unit/Reporting/TtyReporterTest.php src/Cli/Application.php
git commit -m "feat: bounded-mode permanence rules and colour/cursor split in the tty reporter

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: The live window (counter, capacity, elapsed times, overflow)

**Files:**
- Modify: `src/Reporting/TtyReporter.php`
- Test: `tests/Unit/Reporting/TtyReporterTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Reporting/TtyReporterTest.php` (add `use Greenlight\Core\Event\RunStarted;` if missing; it is already imported):

```php
    #[Test]
    public function theWindowShowsACounterAndInFlightClassesWithElapsedTime(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: true, cursor: true);

        $reporter->onEvent(new RunStarted('run-1', 4, 2, 10.0));
        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 10.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Failed), 11.5));

        $buffer = $output->buffer();

        // Counter: done/planned plus a red failure count.
        Expect::that($buffer)->toContain("1/4 tests, \x1b[31m1 failed\x1b[0m")
            // In-flight line: failure mark, running count, elapsed since class start
            // (1.5s crosses the slow threshold, so it renders yellow).
            ->and($buffer)->toContain("\x1b[31m✗\x1b[0m App\AlphaTest (1) \x1b[33m1.500s\x1b[0m");
    }

    #[Test]
    public function inFlightClassesBeyondCapacityCollapseIntoAnOverflowLine(): void
    {
        $output = new BufferOutput();
        // 8 terminal rows clamp the window to 3 lines: counter + 1 class + overflow.
        $reporter = new TtyReporter($output, colour: false, cursor: true, terminalRows: 8);

        $reporter->onEvent(new RunStarted('run-1', 9, 3, 1.0));
        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestClassStarted('App\BetaTest', 1.1));
        $reporter->onEvent(new TestClassStarted('App\GammaTest', 1.2));

        $tail = \substr($output->buffer(), (int) \strrpos($output->buffer(), "\x1b[0J"));

        // Oldest class stays visible; the rest collapse into the overflow line.
        Expect::that($tail)->toContain('App\AlphaTest (0)')
            ->and($tail)->toContain('… and 2 more running')
            ->and($tail)->not()->toContain('App\BetaTest');
    }

    #[Test]
    public function windowCapacityClampsToTerminalHeightWithAFloor(): void
    {
        Expect::that(TtyReporter::windowCapacity(50))->toBe(10)
            ->and(TtyReporter::windowCapacity(12))->toBe(7)
            ->and(TtyReporter::windowCapacity(6))->toBe(3);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./bin/greenlight --filter=TtyReporterTest`
Expected: the three new tests ERROR (`windowCapacity` undefined, unknown named argument `terminalRows`) or FAIL; everything else passes.

- [ ] **Step 3: Implement the window**

In `src/Reporting/TtyReporter.php`:

1. New fields and constructor parameter:

```php
    private int $plannedTests = 0;

    private int $finishedTests = 0;

    private int $failedTests = 0;

    private int $skippedTests = 0;

    private float $lastEventAt = 0.0;

    private readonly int $windowCapacity;
```

```php
    public function __construct(
        private readonly Output\Output $output,
        bool $colour,
        private readonly bool $cursor,
        private readonly ?RunHeader $header = null,
        bool $extendedSlowTests = false,
        private readonly bool $verbose = false,
        int $terminalRows = 24,
    ) {
        $this->style = new Style($colour);
        $this->slowTests = new SlowTests($extendedSlowTests);
        $this->windowCapacity = self::windowCapacity($terminalRows);
    }

    /**
     * At most ten lines, leaving headroom on short terminals, never fewer
     * than three (counter, one class, overflow).
     */
    public static function windowCapacity(int $terminalRows): int
    {
        return \max(3, \min(10, $terminalRows - 5));
    }
```

2. The live-state shape gains a start timestamp. Update the property docblock and both initialisation sites (`TestClassStarted` handling and the `finalizeClass()` default):

```php
    /**
     * @var array<string, array{done: int, failed: int, skipped: int, duration: float, startedAt: float}>
     */
    private array $live = [];
```

```php
        if ($event instanceof TestClassStarted) {
            $this->lastEventAt = $event->occurredAt;
            $this->live[$event->class] = ['done' => 0, 'failed' => 0, 'skipped' => 0, 'duration' => 0.0, 'startedAt' => $event->occurredAt];
            $this->redraw();

            return;
        }
```

In `finalizeClass()`: `$state = $this->live[$class] ?? ['done' => 0, 'failed' => 0, 'skipped' => 0, 'duration' => 0.0, 'startedAt' => 0.0];`

3. Track counts and timestamps in the other handlers:

```php
        if ($event instanceof RunStarted) {
            $this->lastEventAt = $event->occurredAt;
            $this->plannedTests = $event->plannedTests;

            if ($this->header instanceof RunHeader) {
                $this->output->write($this->header->render($event->workers) . "\n\n");
            }

            return;
        }
```

In the `TestFinished` branch, after `$result = $event->result;` add:

```php
            $this->lastEventAt = $event->occurredAt;
            ++$this->finishedTests;

            if (!$result->outcome->isSuccessful()) {
                ++$this->failedTests;
            } elseif ($result->outcome === Outcome::Skipped) {
                ++$this->skippedTests;
            }
```

(The pre-existing per-class `failed`/`skipped` bookkeeping stays as it is.)

4. Replace `redraw()`:

```php
    private function redraw(): void
    {
        if (!$this->cursor) {
            return;
        }

        $this->eraseLiveRegion();
        $this->spinnerFrame = ($this->spinnerFrame + 1) % \count(self::SPINNER);
        $lines = [$this->counterLine(self::SPINNER[$this->spinnerFrame])];

        $slots = $this->windowCapacity - 1;
        $visible = $this->live;
        $overflow = 0;

        if (\count($this->live) > $slots) {
            $visible = \array_slice($this->live, 0, $slots - 1, preserve_keys: true);
            $overflow = \count($this->live) - \count($visible);
        }

        foreach ($visible as $class => $state) {
            $mark = $state['failed'] > 0 ? $this->style->fail('✗') : ' ';
            $lines[] = \sprintf(
                '%s %s (%d) %s',
                $mark,
                $class,
                $state['done'],
                $this->style->duration(\max(0.0, $this->lastEventAt - $state['startedAt'])),
            );
        }

        if ($overflow > 0) {
            $lines[] = \sprintf('  … and %d more running', $overflow);
        }

        foreach ($lines as $line) {
            $this->output->write($line . "\n");
        }

        $this->drawnLines = \count($lines);
    }

    private function counterLine(string $spinner): string
    {
        $line = \sprintf('%s %d/%d tests', $this->style->dim($spinner), $this->finishedTests, $this->plannedTests);

        if ($this->failedTests > 0) {
            $line .= ', ' . $this->style->fail(\sprintf('%d failed', $this->failedTests));
        }

        if ($this->skippedTests > 0) {
            $line .= ', ' . $this->style->skip(\sprintf('%d skipped', $this->skippedTests));
        }

        return $line;
    }
```

5. Extend the class docblock's live-region paragraph:

```
 * The live region holds a progress counter (done/planned, failure and skip
 * tints) and one line per in-flight class, oldest first, each with a
 * running count and an elapsed time that escalates through the slow-colour
 * thresholds. Capacity clamps to min(10, terminal rows - 5); classes past
 * capacity collapse into a single overflow line.
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./bin/greenlight --filter=TtyReporterTest && ./bin/greenlight --filter=Reporting && composer phpstan`
Expected: all pass, phpstan clean.

- [ ] **Step 5: Commit**

```bash
git add src/Reporting/TtyReporter.php tests/Unit/Reporting/TtyReporterTest.php
git commit -m "feat: bounded live window with progress counter and overflow

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: CLI wiring (--no-ansi, --verbose, detection, terminal rows, help)

**Files:**
- Modify: `src/Cli/Application.php` (`buildReporter()`, `HELP` text, `parser()` OptionSpec list)
- Test: `tests/Acceptance/CliTest.php`

- [ ] **Step 1: Write the failing acceptance test**

Add to `tests/Acceptance/CliTest.php`, next to `runExecutesAPassingSuiteAndExitsZero` and using the same `runCli()` helper, `Check` assertion style, and `tests/Fixture/ListTestsConfig` fixture that test uses:

```php
    #[Test]
    public function noAnsiAndVerboseAreAcceptedAndOutputStaysEscapeFree(): void
    {
        [$exit, $output] = $this->runCli(['run', '--no-ansi', '--verbose'], 'tests/Fixture/ListTestsConfig');

        Check::same(0, $exit, 'no-ansi verbose run exit code');
        Check::true(
            !\str_contains(\implode("\n", $output), "\x1b["),
            'no escape sequences under --no-ansi',
        );
        Check::true(
            \str_contains(\implode("\n", $output), '7 tests, 7 passed'),
            'summary line still present',
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./bin/greenlight --filter=CliTest::noAnsiAndVerboseAreAcceptedAndOutputStaysEscapeFree`
Expected: FAIL with a usage error mentioning the unknown `--no-ansi` option (exit 64).

- [ ] **Step 3: Implement the wiring**

In `src/Cli/Application.php`:

1. Add to the `parser()` OptionSpec list, next to `new OptionSpec('dry-run'),`:

```php
            new OptionSpec('no-ansi'),
            new OptionSpec('verbose'),
```

2. Add to `HELP` after the `--detect-leaks` line:

```
          --verbose          Print a permanent line per completed class in
                             interactive output
          --no-ansi          Disable colours and the live progress window;
                             plain append-only output
```

3. Replace the top of `buildReporter()`:

```php
    private function buildReporter(ParsedArguments $arguments, ?int $seed, string $configFile, string $workingDirectory): Reporter
    {
        $output = new StreamOutput(\STDOUT);
        $capabilities = TerminalCapabilities::detect(
            \function_exists('stream_isatty') && @\stream_isatty(\STDOUT),
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $arguments->has('no-ansi'),
        );

        $names = $arguments->values('reporter');

        if ($names === []) {
            $names = [$capabilities->interactive ? 'tty' : 'plain'];
        }
```

4. Replace the `'tty' =>` arm (from Task 3's interim version):

```php
                'tty' => new TtyReporter(
                    $output,
                    $capabilities->colour,
                    $capabilities->interactive,
                    $header,
                    extendedSlowTests: $profile,
                    verbose: $arguments->has('verbose'),
                    terminalRows: $this->terminalRows(),
                ),
```

5. Delete the now-unused `$ansi = ...` line and add the probe method near `buildReporter()`:

```php
    /**
     * LINES when the shell exports it, tput as fallback, 24 as the safe
     * default; probed once per reporter build, no resize handling.
     */
    private function terminalRows(): int
    {
        $lines = (int) (\getenv('LINES') ?: 0);

        if ($lines > 0) {
            return $lines;
        }

        $probed = (int) @\exec('tput lines 2>/dev/null');

        return $probed > 0 ? $probed : 24;
    }
```

6. `TerminalCapabilities` is in the same namespace (`Greenlight\Cli`), so no import is needed.

- [ ] **Step 4: Run the acceptance test and the completion goldens**

Run: `./bin/greenlight --filter=CliTest && ./bin/greenlight --filter=CompletionScriptsTest && ./bin/greenlight --filter=CompletionTest`
Expected: all pass. Completion scripts generate from the OptionSpec list, so the new flags appear automatically; if a golden enumerates flags explicitly, add `--no-ansi` and `--verbose` to it.

- [ ] **Step 5: Commit**

```bash
git add src/Cli/Application.php tests/Acceptance/CliTest.php
git commit -m "feat: --no-ansi and --verbose flags with CI and NO_COLOR detection

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Docs and full verification

**Files:**
- Modify: `docs/configuration.md` (CLI options list and the reporter paragraph at the end)

- [ ] **Step 1: Update docs**

In `docs/configuration.md`, add to the options list (match the surrounding bullet style):

```
- `--verbose` prints a permanent line per completed class in interactive output.
- `--no-ansi` disables colours and the live progress window; output becomes plain and append-only. A truthy `CI` environment variable has the same effect, and `NO_COLOR` disables colours only.
```

Replace the reporter paragraph's first sentence ("The `tty` and `plain` reporters start with a one-line header...") with:

```
In an interactive terminal the `tty` reporter shows a bounded live window: a progress counter and the in-flight classes (at most ten lines, clamped to the terminal height), with failures and skips printed permanently the moment their class finishes. Cleanly passing classes only advance the counter; `--verbose` restores a line per class. Both human reporters start with a one-line header (version, PHP version, config file, seed when randomized, worker count) and end with a "Slowest tests" block naming the five slowest tests when any test took 500 ms or longer; fast suites print nothing extra. `--profile` extends the list to twenty-five entries.
```

- [ ] **Step 2: Run every quality gate**

Run:

```bash
./bin/greenlight && composer phpstan && composer deptrac && composer code-style:check && composer rector:check
```

Expected: full suite green (the suite runs non-TTY here, so it exercises the plain path; the tty path is covered by unit tests), all gates clean. Fix any style/rector findings with `composer code-style:fix` / `composer rector:fix` and re-run.

- [ ] **Step 3: Manual smoke test of the live window**

```bash
script -q /dev/null ./bin/greenlight 2>/dev/null | tail -30 | cat -v
CI=1 script -q /dev/null ./bin/greenlight --filter=StyleTest 2>/dev/null | cat -v | head -5
```

Expected: first command shows escape sequences (live window ran) and a scrollback free of per-class `✓` lines; second shows zero `^[[` sequences despite the PTY.

- [ ] **Step 4: Grep deliverables for banned style**

```bash
grep -rn '—' src/ docs/configuration.md && echo FOUND || echo clean
```

Expected: `clean` (em-dashes are banned in this project's prose).

- [ ] **Step 5: Commit**

```bash
git add docs/configuration.md
git commit -m "docs: bounded live progress, --verbose and --no-ansi

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
