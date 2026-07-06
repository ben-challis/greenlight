# Phase 12b: watch mode

| | |
|---|---|
| **Track** | integration (serial). The loop sits on the Cli/Runner seam, so a single owner is right, and it lands after Phase 12 has proven that seam stable under self-hosting. |
| **Unblocked by** | Phase 12 (Phase 10 additionally, for coverage-based affected-test selection only) |
| **PRD sections** | 7.2 (flow control, watch mode), 11 (per-test coverage mapping) |
| **Writes to** | `src/Cli/Watch/`, `src/Config/`, `tests/` |

## Goals

`greenlight --watch` re-runs tests on file change with debouncing. It is a loop around the normal runner, not a new execution mode: each iteration is an ordinary run producing ordinary events.

## Key tasks

- An owned polling stat-based filesystem watcher: snapshot mtime and size for the configured source and test paths, diff snapshots on an interval. This is the portable default everywhere PHP runs. Native FSEvents/inotify backends are explicitly out of scope for v1 because zero runtime dependencies is a hard rule; the watcher sits behind a small interface so a native backend can be added later without touching the loop.
- Quiet-period debounce: a detected change starts (or restarts) a quiet timer, and a run triggers only once no further change arrives for the quiet period. Default 200 ms. Configurable via a `watch()` sub-builder on `GreenlightConfig` (`->watch(fn ($w) => $w->debounceMilliseconds(200))`), mirroring the `coverage()` sub-builder shape from PRD section 6. This coalesces bursts such as a branch switch into a single run.
- Change-to-test mapping, in two iterations. First iteration: any change re-runs the whole filtered suite; the Phase 3 filter engine applies unchanged. Second iteration: when per-test coverage mapping from Phase 10 is enabled and available, select only affected tests, falling back to path heuristics (a changed test file re-runs itself; a changed src file re-runs tests matched by path convention). Affected-test selection is a pure function from changed paths, mapping, and plan to a test set, testable with no filesystem.
- Wrap the runner loop at the Cli/Runner seam. Reporters need no watch awareness because every iteration emits the normal event stream; the one reporter-adjacent behaviour is that the TTY reporter may clear the screen between iterations.
- Failed-first ordering on re-runs: the loop keeps the previous iteration's failure set in memory and orders those tests first in the next plan. Nothing is persisted to disk for this in v1; the `--rerun-failed` cache is a separate existing mechanism and is not reused here.
- Interactive keys: Enter forces a full re-run and q quits. All other interactivity (filter editing, coverage toggles, key menus) is out of scope for v1.

## Deliverables

`src/Cli/Watch/`, the `watch()` surface on the builder in `src/Config/`; `bin/greenlight --watch` against a fixture project re-runs on a file touch and quits on q.

## Design decisions

- Polling over native watchers. FSEvents/inotify would mean ext dependencies or FFI; a stat-based poller is portable, small, and owned. The interval is a tuning knob, not an API commitment.
- Quiet-period debounce rather than leading-edge: developers save in bursts, and running on the first event of a burst wastes a run on stale state.
- Whole-suite re-run ships first. Affected-test selection is an optimisation layered on the same loop, so the phase is useful before Phase 10's mapping is wired in.
- The failure set lives in memory only. A watch session is one process; persisting it would duplicate `--rerun-failed` semantics for no benefit.

## Dependencies

Phase 12 (the loop drives the same `bin/greenlight` entry point the cutover stabilised). Phase 10's per-test coverage mapping upgrades affected-test selection but does not gate the phase.

## Risks

Polling cost on large trees. Mitigation: one stat pass per interval over the configured path set, benchmarked against a large fixture tree, with the pass budgeted so a slow filesystem degrades to a longer effective interval rather than a busy loop.

## Validation

- Debounce is tested deterministically: the loop takes an injectable clock/scheduler, tests advance virtual time and assert exactly which instants trigger runs. No sleep-based tests anywhere in the phase.
- Acceptance tests drive the watcher with synthetic file touches in a temp directory fixture and assert which runs trigger, in what order, and with which test sets.
- Affected-test selection is covered by table-driven unit tests over the pure function (changed paths plus mapping in, test set out), including the fallback path heuristics.
