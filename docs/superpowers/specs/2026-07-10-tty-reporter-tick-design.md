# TTY reporter live tick

## Problem

The TTY reporter's live window shows an elapsed duration per in-flight class, computed as `lastEventAt - startedAt`. Both values only advance when an event arrives, so durations (and the spinner) freeze whenever no test finishes. A long-running test looks stalled at whatever the clock read when the previous event landed.

## Approach

Drive the live display from the orchestrator's existing run loop rather than from the event stream. `Orchestrator::tick()` already wakes at least every 200ms (`stream_select` with a 200ms timeout), which gives a ~5fps cadence with no new timer machinery. The event stream and plugin subscribers are untouched: no synthetic events.

## Components

### `Greenlight\Reporting\Ticking` (new interface)

```php
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

`Reporter` itself is unchanged, so existing reporters (JUnit, JSON lines, TeamCity, plain, GitHub) are unaffected.

### `TtyReporter`

Implements `Ticking`. `tick(float $now)`:

- Returns immediately when cursor support is off or the live window is empty (before `RunStarted`, after the last class finishes).
- Otherwise sets `lastEventAt = $now` and calls `redraw()`.

This is safe for the elapsed-time math: `max(0.0, lastEventAt - startedAt)` already clamps, and event handlers overwrite `lastEventAt` with their own `occurredAt`.

### Redraw throttle

`redraw()` gains a minimum interval of ~50ms, tracked via a `lastDrawAt` timestamp. Redraws requested sooner are skipped. This caps flicker during event bursts (redraw currently runs on every event) and makes the added tick redraws harmless. The spinner frame advances only on actual draws. `finalizeClass()` and `finish()` bypass the throttle because they erase and replace the live region; skipping there would corrupt output.

### Driver wiring

`ParallelRunner` holds the reporter and passes it to the orchestrator as an optional `?Ticking` collaborator (constructor or `run()` parameter). The orchestrator calls `tick(microtime(true))` once per loop iteration, after `tick()` has pumped channels. When the reporter is not `Ticking` (or null), nothing happens.

`CompositeReporter` also implements `Ticking`, forwarding to children that are `instanceof Ticking`, so the wiring works whether the reporter is bare or composed.

## Out of scope

- `InProcessRunner`: it blocks inside test code with no loop to tick from. Output degrades exactly as today.
- Any change to event types, the wire protocol, or plugin-visible behaviour.

## Testing

- `TtyReporter` unit tests: `tick()` advances displayed durations without a `TestFinished`; no output when cursor support is off or the window is empty; the throttle suppresses back-to-back redraws; `finalizeClass()` output is not throttled.
- Orchestrator or acceptance-level check that the ticker is invoked during a run.
- Existing tests drive redraws via events with fixed `occurredAt` values; new tests stay deterministic by passing explicit `$now` values to `tick()`.
