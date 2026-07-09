# TTY Reporter Live Tick Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The TTY reporter's live window updates in-flight class durations and the spinner roughly five times per second, even when no test finishes.

**Architecture:** A new opt-in `Ticking` interface in `Greenlight\Reporting`. `TtyReporter` and `CompositeReporter` implement it. The orchestrator's existing run loop (which already wakes at least every 200ms via its `stream_select` timeout) calls `tick(microtime(true))` on an optional `Ticking` collaborator threaded in from `Application` through `ParallelRunner`. A ~50ms minimum redraw interval inside `TtyReporter` caps burst flicker. No new events, no wire-protocol changes, no plugin-visible changes.

**Tech Stack:** PHP 8.4, greenlight testing itself (`composer tests` runs `bin/greenlight run`), phpstan, php-cs-fixer, rector, deptrac.

**Spec:** `docs/superpowers/specs/2026-07-10-tty-reporter-tick-design.md`

**Deptrac note:** `Runner: [..., Reporting, ...]` is already an allowed dependency in `deptrac.yaml`, so `Orchestrator` and `ParallelRunner` may import `Greenlight\Reporting\Ticking`.

**Shared working tree warning:** `git status` already shows unrelated modified files. Stage only the files each task names, never `git add -A` or `git add .`. Before each commit, `git diff <file>` every file you touched and confirm the hunks are yours.

**Test conventions:** greenlight tests itself. Tests are plain classes with `#[Test]` methods using `Greenlight\Attribute\Test` and `Greenlight\Expect\Expect`. Run a single class with `php bin/greenlight run --filter=TtyReporterTest` from the repo root. `BufferOutput` (in `tests/Unit/Reporting/`) is the in-memory output used by reporter tests. With `colour: false`, `Style::dim()`/`duration()` return plain text; `duration()` formats as `%.3fs` (e.g. `2.500s`).

---

### Task 1: `Ticking` interface and `TtyReporter::tick()`

**Files:**
- Create: `src/Reporting/Ticking.php`
- Modify: `src/Reporting/TtyReporter.php`
- Test: `tests/Unit/Reporting/TtyReporterTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Reporting/TtyReporterTest.php` (inside the class, alongside the existing tests; all imports it needs are already present):

```php
#[Test]
public function tickAdvancesInFlightDurationsWithoutEvents(): void
{
    $output = new BufferOutput();
    $reporter = new TtyReporter($output, colour: false, cursor: true);

    $reporter->onEvent(new RunStarted('run-1', 1, 1, 1.0));
    $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
    $reporter->tick(3.5);

    Expect::that($output->buffer())->toContain('App\AlphaTest (0)')
        ->and($output->buffer())->toContain('2.500s');
}

#[Test]
public function tickWithoutCursorSupportWritesNothing(): void
{
    $output = new BufferOutput();
    $reporter = new TtyReporter($output, colour: false, cursor: false);

    $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
    $reporter->tick(2.0);

    Expect::that($output->buffer())->toBe('');
}

#[Test]
public function tickWithNoClassesInFlightWritesNothing(): void
{
    $output = new BufferOutput();
    $reporter = new TtyReporter($output, colour: false, cursor: true);

    $reporter->onEvent(new RunStarted('run-1', 1, 1, 1.0));
    $reporter->tick(2.0);

    Expect::that($output->buffer())->toBe('');
}
```

Why these assertions: the live window line for a class renders as `<mark> <class> (<done>) <elapsed>`; elapsed is `lastEventAt - startedAt`, so a tick at 3.5 against a class started at 1.0 must render `2.500s`. Without cursor support the live region is never drawn, and before any `TestClassStarted` there is nothing to draw, so both no-op cases must write zero bytes.

- [ ] **Step 2: Run the tests to verify they fail**

Run from the repo root: `php bin/greenlight run --filter=TtyReporterTest`

Expected: FAIL — `tick()` does not exist on `TtyReporter` (error mentioning undefined method `tick`).

- [ ] **Step 3: Create the interface**

Create `src/Reporting/Ticking.php`:

```php
<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * Opt-in for reporters that render a live display and want wall-clock
 * updates between events.
 *
 * @internal
 */
interface Ticking
{
    public function tick(float $now): void;
}
```

- [ ] **Step 4: Implement `tick()` on `TtyReporter`**

In `src/Reporting/TtyReporter.php`:

Change the class declaration:

```php
final class TtyReporter implements Reporter, Ticking
```

Add this method after `onEvent()` (before `finish()`):

```php
#[\Override]
public function tick(float $now): void
{
    if (!$this->cursor || $this->live === []) {
        return;
    }

    $this->lastEventAt = $now;
    $this->redraw();
}
```

Setting `lastEventAt` is safe: the elapsed-time math clamps with `max(0.0, ...)` and every event handler overwrites it with its own `occurredAt`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/greenlight run --filter=TtyReporterTest`

Expected: PASS (all TtyReporterTest tests, existing ones included).

- [ ] **Step 6: Commit**

```bash
git add src/Reporting/Ticking.php src/Reporting/TtyReporter.php tests/Unit/Reporting/TtyReporterTest.php
git commit -m "feat: add Ticking interface and TtyReporter tick"
```

Note: `TtyReporter.php` and `TtyReporterTest.php` may contain pre-existing unrelated modifications. Diff them first; if foreign hunks exist, stage only your hunks (`git add -p`) and say so in your report.

---

### Task 2: Redraw throttle in `TtyReporter`

**Files:**
- Modify: `src/Reporting/TtyReporter.php`
- Test: `tests/Unit/Reporting/TtyReporterTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Reporting/TtyReporterTest.php`:

```php
#[Test]
public function redrawsInsideTheThrottleWindowAreSkipped(): void
{
    $output = new BufferOutput();
    $reporter = new TtyReporter($output, colour: false, cursor: true);

    $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
    $before = $output->buffer();

    $reporter->tick(1.01);

    Expect::that($output->buffer())->toBe($before);

    $reporter->tick(1.2);

    Expect::that($output->buffer())->not()->toBe($before);
}

#[Test]
public function classFinalizationBypassesTheThrottle(): void
{
    $output = new BufferOutput();
    $reporter = new TtyReporter($output, colour: false, cursor: true);

    $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
    $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Failed), 1.01));
    $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.02));

    Expect::that($output->buffer())->toContain('✗ App\AlphaTest (1 test, 1 failed');
}
```

The second test pins the bypass: the `TestFinished` redraw at 1.01 falls inside the 50ms window and is skipped, but the permanent class line written by `finalizeClass()` at 1.02 must still appear.

- [ ] **Step 2: Run the tests to verify the first one fails**

Run: `php bin/greenlight run --filter=TtyReporterTest`

Expected: `redrawsInsideTheThrottleWindowAreSkipped` FAILS (the tick at 1.01 currently redraws, so the buffer grows). `classFinalizationBypassesTheThrottle` may already pass — that is fine; it exists to pin behaviour once the throttle lands.

- [ ] **Step 3: Implement the throttle**

In `src/Reporting/TtyReporter.php`:

Add a constant next to `SPINNER`:

```php
private const float REDRAW_INTERVAL_SECONDS = 0.05;
```

Add a property next to `$lastEventAt`:

```php
private float $lastDrawAt = -\INF;
```

Change the top of `redraw()` from:

```php
private function redraw(): void
{
    if (!$this->cursor) {
        return;
    }

    $this->eraseLiveRegion();
```

to:

```php
private function redraw(): void
{
    if (!$this->cursor || $this->lastEventAt - $this->lastDrawAt < self::REDRAW_INTERVAL_SECONDS) {
        return;
    }

    $this->lastDrawAt = $this->lastEventAt;
    $this->eraseLiveRegion();
```

The throttle compares event/tick timestamps (not `microtime(true)` read inside `redraw()`), so tests stay deterministic with fixed `occurredAt` values. `-INF` guarantees the first draw always happens. `finalizeClass()` and `finish()` erase via `eraseLiveRegion()` directly and write permanent lines with plain `write()`, so they are unaffected; only the live-window repaint is throttled. The spinner frame advances inside `redraw()` after the throttle check, so it only advances on actual draws.

- [ ] **Step 4: Update the class docblock**

In the `TtyReporter` class comment, replace this paragraph:

```
 * The live region is redrawn on every event, which is also what advances the
 * spinner.
```

with:

```
 * The live region is redrawn on events and on external ticks (see Ticking),
 * throttled to one repaint per 50ms so event bursts do not flicker. The
 * spinner advances once per actual repaint.
```

- [ ] **Step 5: Run the reporter tests**

Run: `php bin/greenlight run --filter=TtyReporterTest`

Expected: PASS. The existing tests use event timestamps ≥0.1s apart (one pair of `TestClassStarted` events shares timestamp 1.0 in `interleavedClassesFinalizeInPlaceWithAnsi`, which now skips one intermediate repaint, but that test asserts on final buffer contents that survive the skip). If an existing test fails because an intermediate live-window snapshot no longer appears, space that test's event timestamps at least 0.05s apart rather than weakening the assertion.

- [ ] **Step 6: Run the full unit suite for the reporting namespace**

Run: `php bin/greenlight run --filter=Reporting`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Reporting/TtyReporter.php tests/Unit/Reporting/TtyReporterTest.php
git commit -m "feat: throttle TtyReporter repaints to 50ms"
```

---

### Task 3: `CompositeReporter` forwards ticks

**Files:**
- Modify: `src/Reporting/CompositeReporter.php`
- Create: `tests/Unit/Reporting/CompositeReporterTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Reporting/CompositeReporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\Event;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\CompositeReporter;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\Ticking;

final class CompositeReporterTest
{
    #[Test]
    public function ticksReachOnlyTickingReporters(): void
    {
        $plain = new RecordingReporter();
        $live = new RecordingTickingReporter();

        new CompositeReporter([$plain, $live])->tick(1.5);

        Expect::that($live->ticks)->toBe([1.5])
            ->and($plain->events)->toBe([]);
    }
}

final class RecordingReporter implements Reporter
{
    /**
     * @var list<Event>
     */
    public array $events = [];

    #[\Override]
    public function onEvent(Event $event): void
    {
        $this->events[] = $event;
    }

    #[\Override]
    public function finish(): void {}
}

final class RecordingTickingReporter implements Reporter, Ticking
{
    /**
     * @var list<float>
     */
    public array $ticks = [];

    #[\Override]
    public function onEvent(Event $event): void {}

    #[\Override]
    public function tick(float $now): void
    {
        $this->ticks[] = $now;
    }

    #[\Override]
    public function finish(): void {}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/greenlight run --filter=CompositeReporterTest`

Expected: FAIL — `tick()` is not defined on `CompositeReporter`.

- [ ] **Step 3: Implement forwarding**

In `src/Reporting/CompositeReporter.php`, change the class declaration:

```php
final readonly class CompositeReporter implements Reporter, Ticking
```

Add after `onEvent()`:

```php
#[\Override]
public function tick(float $now): void
{
    foreach ($this->reporters as $reporter) {
        if ($reporter instanceof Ticking) {
            $reporter->tick($now);
        }
    }
}
```

Append one sentence to the class comment's first paragraph block (after the onEvent/finish ordering paragraph):

```
 * tick() reaches only the reporters that opt into Ticking.
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/greenlight run --filter=CompositeReporterTest`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Reporting/CompositeReporter.php tests/Unit/Reporting/CompositeReporterTest.php
git commit -m "feat: forward ticks through CompositeReporter"
```

---

### Task 4: Drive ticks from the orchestrator loop

**Files:**
- Modify: `src/Runner/Orchestrator/Orchestrator.php`
- Modify: `src/Runner/ParallelRunner.php`
- Modify: `src/Cli/Application.php` (two `ParallelRunner->run(...)` call sites, around lines 298 and 419)

There is no unit-test harness for `Orchestrator` (it spawns real worker processes); the wiring is covered by the acceptance suite as a regression gate plus a manual TTY check in Task 5.

- [ ] **Step 1: Add the ticker to `Orchestrator`**

In `src/Runner/Orchestrator/Orchestrator.php`:

Add the import (alphabetical among the `Greenlight\` imports):

```php
use Greenlight\Reporting\Ticking;
```

Add a constructor parameter after `$shutdown`:

```php
private readonly ?GracefulShutdown $shutdown = null,
private readonly ?Ticking $ticker = null,
```

In `run()`, change the loop body's last line from:

```php
$this->tick($server, $token, $sink);
```

to:

```php
$this->tick($server, $token, $sink);
$this->ticker?->tick(\microtime(true));
```

Append this paragraph to the class comment, after the paragraph about worker placement:

```
 * An optional Ticking collaborator is ticked once per loop iteration. The
 * select timeout bounds the interval at 200ms, so a live display keeps
 * advancing even when no worker sends anything.
```

- [ ] **Step 2: Thread the ticker through `ParallelRunner`**

In `src/Runner/ParallelRunner.php`:

Add the import:

```php
use Greenlight\Reporting\Ticking;
```

Add a parameter to `run()` after `?GracefulShutdown $shutdown = null,`:

```php
?Ticking $ticker = null,
```

Pass it to the `Orchestrator` constructor after `$shutdown,`:

```php
$shutdown,
$ticker,
```

- [ ] **Step 3: Pass the reporter from `Application`**

In `src/Cli/Application.php`:

Add the import (the file already imports several `Greenlight\Reporting\` classes; keep alphabetical order):

```php
use Greenlight\Reporting\Ticking;
```

At both `ParallelRunner->run(...)` call sites — the main run path (around line 298) and the watch path (around line 419) — append one argument. Main path, currently:

```php
$run = new ParallelRunner([\PHP_BINARY, $realBin], $workingDirectory)
    ->run($resolved, $this->directories($resolved, $workingDirectory), $failedTap, $workers, $coverageSettings, $configFile, $detectLeaks, $priorityClasses, $classSeconds, $shutdown);
```

becomes:

```php
$run = new ParallelRunner([\PHP_BINARY, $realBin], $workingDirectory)
    ->run($resolved, $this->directories($resolved, $workingDirectory), $failedTap, $workers, $coverageSettings, $configFile, $detectLeaks, $priorityClasses, $classSeconds, $shutdown, $reporter instanceof Ticking ? $reporter : null);
```

Watch path, currently:

```php
$run = new ParallelRunner([\PHP_BINARY, $binPath], $workingDirectory)
    ->run($resolved, $directories, $tap, $workers, $coverageSettings, $configFile, $detectLeaks, $priorityClasses, $classSeconds, $shutdown);
```

becomes the same call with `, $reporter instanceof Ticking ? $reporter : null` appended before the closing parenthesis. (In both scopes `$reporter` is the variable already wrapped by `new ReporterSink($reporter)` a few lines above; exact argument lists may differ slightly from the snippets — append the new argument to whatever is there, do not reorder.)

The `InProcessRunner` branches are untouched: it blocks inside test code with no loop to tick from.

- [ ] **Step 4: Run the acceptance suite as a regression gate**

Run: `php bin/greenlight run --filter=ParallelRunTest`

Expected: PASS (these drive `bin/greenlight` with real worker pools; they run piped, so `cursor` is false and ticks no-op, which is exactly the degradation the spec requires).

- [ ] **Step 5: Commit**

```bash
git add src/Runner/Orchestrator/Orchestrator.php src/Runner/ParallelRunner.php src/Cli/Application.php
git commit -m "feat: tick live reporters from the orchestrator loop"
```

`Application.php` has pre-existing unrelated modifications per `git status` — diff it first and stage only your hunks (`git add -p`) if foreign changes are present.

---

### Task 5: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run from the repo root: `composer tests`

Expected: exit 0, summary line with 0 failures.

- [ ] **Step 2: Run static analysis and style gates**

Run: `composer phpstan && composer code-style:check && composer rector:check && composer deptrac && composer lint`

Expected: all exit 0. If `code-style:check` flags only files this plan touched, run `composer code-style:fix`, re-diff to confirm the fixer only touched your files, and amend the relevant commit.

- [ ] **Step 3: Manual TTY verification**

This needs a real terminal (the acceptance tests run piped, where ticks intentionally no-op). In an interactive terminal at the repo root:

```bash
php bin/greenlight run --workers=4
```

Watch the live window: per-class elapsed times and the spinner must advance smoothly (~5fps) even between test completions, most visibly on slower classes. Confirm no flicker regression and that failed/skipped class lines still print correctly. If you cannot allocate a TTY, report that this step needs the user to eyeball it.

- [ ] **Step 4: Grep gates from project memory**

```bash
git diff main --stat
git diff ed1617a -- src/Reporting/Ticking.php src/Reporting/TtyReporter.php src/Reporting/CompositeReporter.php src/Runner/ParallelRunner.php src/Runner/Orchestrator/Orchestrator.php src/Cli/Application.php tests/Unit/Reporting/CompositeReporterTest.php | grep -nE '—|RFC|PRD|docs/plan'
```

Expected: no matches (no em-dashes in code or comments, no references to spec/plan documents in code comments). Fix any hits and amend.
