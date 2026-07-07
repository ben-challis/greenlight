# Phase 18: worker spawn and protocol cost

| | |
|---|---|
| **Track** | post-1.0, track I (parallelisable: fork spawn, frame batching, and discovery cache are independent) |
| **Unblocked by** | Phase 16 (only worth doing where the profile says spawn or protocol cost matters), Phase 17 (assignment shape settles first) |
| **PRD sections** | 7.1 (architecture), 12 (memory and lifecycle principles) |
| **Writes to** | `src/Runner/Orchestrator/`, `src/Runner/Protocol/`, `src/Discovery/`, `tests/` |

## Goals

Three independent cost reductions, each behind the measurements Phase 16 provides: cheaper worker creation via optional `pcntl_fork`, fewer socket writes via event frame batching, and cheaper cold starts via a discovery cache. Any of the three can ship alone or be dropped if the profile says it does not pay.

## Key tasks

- Fork-based spawn, optional. Where `function_exists('pcntl_fork')` (POSIX with ext-pcntl loaded), the orchestrator boots one worker template process (autoloader parsed, config loaded, plugins constructed) and forks it per worker and per recycle, getting copy-on-write memory and near-zero incremental boot. `proc_open` remains the only portable path and the fallback, following the exact optional-integration pattern used for the CPU core counter. The template process must be socket-free at fork time (each child connects fresh) because sharing a connected socket across forks is how protocol corruption happens.
- Event frame batching. Workers currently write one frame per event; a chatty suite pays a syscall per test event. Workers buffer event frames and flush on class completion, on a small byte threshold, or on any state-changing message (recycling, done, fatal), whichever comes first. Ordering within a worker is preserved by construction; the orchestrator's pump loop is unchanged because frames are already length-prefixed and self-describing. The tty live display tolerates class-granular flushes by design since its unit of progress is the class.
- Discovery cache. Static discovery re-parses every test file every run. Cache per-file discovery output (the plan entries derived from a file) keyed by path, mtime, and size in the temp-dir state location; a hit skips parsing, a miss re-parses one file. Correctness rule: any doubt (missing file, size or mtime mismatch, cache version bump) falls back to parsing. Watch mode benefits most, since its iterations re-discover constantly.

## Deliverables

Each lever shipped or explicitly dropped with the profile numbers recorded either way; re-measured Phase 16 baseline; docs note ext-pcntl as a suggested extension for faster spawning if fork ships.

## Design decisions

- Fork is an optimisation, never a requirement, and recycling semantics do not change: a recycled worker is still a brand-new process (a fresh fork), so the flat-memory guarantee is untouched.
- Batching flushes on class boundaries rather than timers. A timer would add wakeups and jitter to the orchestrator loop; class granularity matches what every consumer of live progress already renders.
- The discovery cache stores derived plan entries, not parsed ASTs, because entries are small, wire-shaped, and versioned by the cache format; ASTs are none of those.

## Dependencies

Phase 16 numbers pick which levers matter. Phase 17 lands first so batching and fork integrate with per-class assignment rather than being built twice.

## Risks

Fork inherits state copied at fork time: opcache, random seeds, time-sensitive plugin constructor state. Mitigation: reseed randomness post-fork, keep the template minimal (no test has run in it), and gate the whole path behind a config flag during a bake-in period so any suspicion is answerable with "turn it off and compare".

## Validation

- Fork path: the full self-hosted suite and the crash/recycling acceptance fixtures run green with fork active where the platform supports it, and CI runs both modes.
- Batching: protocol unit tests assert event ordering and flush triggers; the summary cross-check (which counts everything) is the systemic guard that no event is lost in a buffer.
- Discovery cache: unit tests for hit, miss, and every doubt-falls-back rule; an acceptance test touches one file and asserts exactly one re-parse; a cache poisoned with stale entries must produce a full re-parse, never a wrong plan.
