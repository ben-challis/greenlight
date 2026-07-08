# RFC-005: bounded live progress output

| | |
|---|---|
| **Status** | accepted |
| **Author** | Ben Challis |
| **Date** | 2026-07-08 |

## Context

An interactive run currently appends one permanent line per completed test class. A green run of this repository prints roughly a hundred `✓` lines that nobody reads, and the useful signal (the summary, a failure, a hang) drowns in them. The `TtyReporter` already maintains a live region for in-flight classes; the spam comes entirely from the finalised lines.

This RFC decides the interactive output model: a bounded live window that shows current activity, permanent scrollback reserved for notable events, and explicit rules for every non-interactive context so no cursor-control sequence can ever reach a log file.

## Decision

### Mode selection

A new `Greenlight\Cli\TerminalCapabilities` value object decides the output mode. Detection is a pure function of its inputs so the full matrix is unit-testable:

```php
/**
 * @internal
 */
final readonly class TerminalCapabilities
{
    public function __construct(
        public bool $interactive, // live window and cursor control allowed
        public bool $colour,      // ANSI colour allowed
    ) {}

    /**
     * @param array<string, string|false> $env getenv() snapshot
     */
    public static function detect(bool $stdoutIsTty, array $env, bool $noAnsiFlag): self;
}
```

Rules, in order:

- `--no-ansi` or a truthy `CI` env var or a non-TTY stdout: not interactive, no colour.
- `NO_COLOR` set (any non-empty value): colour off, interactivity unaffected.
- Otherwise: interactive with colour.

Reporter choice in `Application::buildReporter` becomes: default reporter is `tty` when interactive, `plain` otherwise. An explicit `--reporter=` always wins, as today. Two new run options exist:

- `--no-ansi`: forces the non-interactive path (plain reporter by default; a `tty` reporter selected explicitly degrades to its append-only fallback).
- `--verbose`: the `tty` reporter prints one permanent line per completed class, exactly the pre-RFC behaviour, while keeping the live window. No effect on other reporters.

There is no `--no-interaction`; the runner reads no input outside `--watch`, and the combination of TTY detection, `CI`, and `--no-ansi` covers every consumer we know of.

### The live window

In bounded mode (interactive, not verbose) the `tty` reporter maintains a live region of at most `min(10, terminalRows - 5)` lines, terminal height probed once at construction (`LINES` env var, then `tput lines`, defaulting to 24). No SIGWINCH handling; a resize mid-run corrects itself on the next run.

The window contains, top to bottom (preceded by one blank line separating it from the permanent scrollback above):

1. One counter line: spinner, `done/planned tests`, plus `N failed` (red) and `N skipped` (yellow) once non-zero. The planned total comes from `RunStarted`.
2. One line per in-flight class: name and running-count rendered dim so the line reads as pending, elapsed seconds through `Style::duration()` so a class running past 1s turns yellow and past 5s red. Ordered by start time, oldest first, so long-runners sit at the top.
3. When in-flight classes exceed the remaining capacity, the last line reads `… and N more running` instead, also dim.

Redraw stays event-driven, exactly as the current live region: every event erases and repaints the window, which is also what advances the spinner and elapsed times. No timers, no threads.

### Permanent scrollback

Only notable events append permanent lines above the window:

- The run header (RFC'd previously; unchanged).
- A class whose run contained a failure or error prints its `✗` line the moment the class finishes. Detail blocks (diffs, traces, captured output) stay in the end-of-run problems section.
- A class containing skips prints its `−` (fully skipped) or `✓ … (N skipped)` line.
- Cleanly passing classes print nothing; they only advance the counter.

A green run's scrollback is therefore the header and the final summary, nothing else.

### Finish

`finish()` erases the live window and prints the existing end-of-run output unchanged in content and order: problem details, summary line, workers, skipped section, slowest tests, risky tests.

One amendment to the skipped section: when two or more tests share a skip reason they group under one reason line, listing at most five ids and then `… and N more`. A reason with a single test keeps the inline `id (reason)` form. A null reason buckets under `no reason given`.

```
Skipped:
  xdebug with coverage mode is not available:
    Greenlight\Tests\Unit\Coverage\XdebugDriverTest::collectsRealLineCoverage
    Greenlight\Tests\Unit\Coverage\XdebugDriverTest::branchCoverage
  Greenlight\Tests\Unit\Cli\WatchTest::pollsNatively (no reason given)
```

### Non-interactive contexts

The behaviour matrix this RFC freezes:

| Context | Behaviour |
|---|---|
| Interactive TTY | Bounded live window (new default). |
| TTY + `--verbose` | Live window plus a permanent line per class (pre-RFC output). |
| Non-TTY / redirected | `plain` reporter: append-only, deterministic, zero escape codes. Unchanged. |
| `CI` env truthy | Forced non-interactive, even with a PTY. |
| `--no-ansi` | Forced non-interactive, no colour. |
| `NO_COLOR` env | Colour stripped; live window retained when otherwise interactive. |
| `--profile` | Final output only (profile block, extended slowest list). No live effect. |
| `--reporter=X` | Explicit choice always wins. |

`plain` output remains byte-identical for identical event streams and is golden-tested to contain no `\x1b`.

### Concurrency

The orchestrator is the sole stdout writer; worker output crosses the wire protocol and is captured per test. No locking or buffering is added, and worker stdout can never interleave with the live region.

## Consequences

- Interactive green runs collapse to header, live window, summary. Failures and skips surface immediately without waiting for the run to end.
- The single `ansi` boolean on `TtyReporter` splits into colour (owned by `Style`) and cursor control (owned by the live region), which is what makes `NO_COLOR` cheap to honour.
- `TerminalCapabilities::detect()` freezes the mode-selection rules; new environment heuristics require touching one function and its test matrix.
- The `tty` reporter grows state (planned totals, in-flight ordering, window capping). If it becomes hard to follow, the live region extracts into an internal `LiveRegion` collaborator; this RFC does not mandate it.
- Anyone relying on per-class `✓` lines in interactive output must pass `--verbose`; scripts parsing output should already be on `plain`, which is unchanged.

## Alternatives considered

**Docker-compose-style rolling window of recent completions.** Compose shows recent lines because containers are few and long-lived. Test classes complete many times per second here, so a rolling history flickers faster than it can be read while consuming most of the window. In-flight classes plus a counter carry the same information calmly.

**One line per worker.** Workers are invisible in the event model and the lines would mostly churn between short-lived classes. The class is already the runner's unit of progress; workers stay a count in the header and summary.

**Opt-in flag (`--compact`) instead of a new default.** Safer for existing eyeballs, but nobody discovers opt-in output modes, and the project is pre-1.0 with `plain` already covering automation. The escape hatch is `--verbose`.

**Full failure details printed mid-run (Jest-style).** Immediate detail blocks interrupt the live window with walls of text and duplicate the end-of-run problems section. The permanent `✗` class line gives the early signal; details keep their single home at the end.

**A `--no-interaction` flag.** Symfony-console convention, but the runner prompts for nothing outside `--watch`. TTY detection, `CI`, and `--no-ansi` cover the same ground without a third overlapping switch.

**Configurable window size.** A `liveLines` config knob is surface without a known customer. The auto-clamp handles small terminals; a knob can be added compatibly later.
