# Greenlight build plan: core reference

This is the shared context for anyone (human or agent) executing a phase. Read this file, your phase file, and the PRD sections your phase file names. Nothing else is required.

**Goal:** build the framework defined in [../PRD.md](../PRD.md) through phases that each end in something runnable.

**Architecture:** a single monorepo with deptrac-enforced component boundaries (`Core`, `Config`, `Discovery`, `Expect`, `Runner`, `Harness`, `Doubles`, `Capture`, `Plugin`, `Reporting`, `Coverage`, `Cli`). Component sub-namespaces (`Greenlight\Expect\`, ...) map to `src/<Component>/`.

**Tech stack:** PHP 8.4+, zero runtime Composer dependencies, dev tooling via PHPStan (max, strict rules, no baseline), PHP-CS-Fixer (PER-CS 2.0 base), Rector, deptrac, parallel-lint, composer-normalize, GitHub Actions. Composer scripts are the only task runner: `composer static-analysis` and `composer tests` must be green before every commit.

## Non-negotiable rules

1. Zero runtime dependencies. `composer.json` `require` contains only `php: >=8.4`. If a phase needs a capability, we own the code.
2. No PHPUnit anywhere, including tests of the framework itself. The suite runs under `tools/bootstrap-runner.php` until Phase 12 cuts over to `bin/greenlight`.
3. PHPStan max with no baseline. deptrac violations fail CI with no baseline. Do not add either baseline file.
4. `@internal` is the default on every class, interface, and enum, to keep the BC promise as small as possible. The only exceptions are the authoring surface users type against: the attributes, `Greenlight\Core\Condition`, the `Expect` facade (entry points and the exception type callers catch), and the `GreenlightConfig` builder surface loaded from `greenlight.php`. Everything else, including the result and event model, stays `@internal` until RFC-004 promotes the plugin-visible subset; that promotion is the deliberate act that creates the BC promise.
5. Wire-crossing types use explicit `toWire()`/`fromWire()` arrays. PHP `serialize()` is banned.
6. Prose style for all docs: no em-dashes, no bold-first bullets, plain sentences.
7. Conventional commits, trunk-based on `main`.

## Phase index

| Phase | File | Track | Unblocked by |
|---|---|---|---|
| 0 Bootstrap | [phase-00-bootstrap.md](phase-00-bootstrap.md) | spine | nothing |
| 1 Core domain model | [phase-01-core-model.md](phase-01-core-model.md) | spine | Phase 0 |
| 2 Config + CLI | [phase-02-config.md](phase-02-config.md) | track A | RFC-001 |
| 3 Discovery | [phase-03-discovery.md](phase-03-discovery.md) | track B | RFC-001 |
| 4 Expect | [phase-04-expect.md](phase-04-expect.md) | track C | RFC-001 |
| 5a Worker + lifecycle | [phase-05a-worker-lifecycle.md](phase-05a-worker-lifecycle.md) | spine | Phases 2, 3, 4 |
| 5b Orchestrator + wire | [phase-05b-orchestrator.md](phase-05b-orchestrator.md) | spine | 5a, RFC-003 |
| 6 Output capture | [phase-06-capture.md](phase-06-capture.md) | track E | RFC-003 |
| 7 Plugin architecture | [phase-07-plugins.md](phase-07-plugins.md) | spine | 5b, 6 |
| 8 Reporters | [phase-08-reporters.md](phase-08-reporters.md) | track F | RFC-003 |
| 9 Doubles | [phase-09-doubles.md](phase-09-doubles.md) | track D | RFC-002 |
| 10 Coverage | [phase-10-coverage.md](phase-10-coverage.md) | track G | RFC-003 |
| 11 Memory gates | [phase-11-memory.md](phase-11-memory.md) | convergence | 5b, 9 |
| 12 Self-hosting cutover | [phase-12-self-hosting.md](phase-12-self-hosting.md) | integration | 4, 5b, 6, 8 |
| 12b Watch mode | [phase-12b-watch.md](phase-12b-watch.md) | integration | Phase 12 (Phase 10 additionally for coverage-based affected-test selection) |
| 13 Docs + plugin API GA | [phase-13-release.md](phase-13-release.md) | integration | all |
| 14 Selection + feedback | [phase-14-selection-feedback.md](phase-14-selection-feedback.md) | track H | Phase 13 |
| 15 Data providers | [phase-15-data-providers.md](phase-15-data-providers.md) | track B follow-on | Phase 14 |
| 16 Profiling | [phase-16-profiling.md](phase-16-profiling.md) | spine | Phase 14 |
| 17 Scheduling + timing cache | [phase-17-scheduling.md](phase-17-scheduling.md) | spine | Phase 16 |
| 18 Spawn + protocol cost | [phase-18-spawn-and-protocol-cost.md](phase-18-spawn-and-protocol-cost.md) | track I | Phases 16, 17 |
| 19 CI gates + sharding | [phase-19-ci-gates.md](phase-19-ci-gates.md) | track J | Phases 14, 17 |
| 20 Validation + ecosystem | [phase-20-validation-ecosystem.md](phase-20-validation-ecosystem.md) | integration | Phases 17, 18 |

Execution order:

```
Phase 0 (bootstrap)                          spine
Phase 1 (core model, RFC-001)                spine
  ├── Phase 4 Expect                         track C (start first)
  ├── Phase 2 Config/CLI                     track A
  └── Phase 3 Discovery                      track B
Phase 5a (worker + lifecycle, RFC-002)       spine
  └── Phase 9 Doubles                        track D
Phase 5b (orchestrator + wire, RFC-003)      spine
  ├── Phase 6 Capture                        track E
  ├── Phase 8 Reporters (per-format)         track F
  └── Phase 10 Coverage                      track G
Phase 7 (plugin API, RFC-004)                spine
Phase 11 (memory gates)                      convergence
Phase 12 (self-hosting cutover)              integration
Phase 12b (watch mode)                       integration
Phase 13 (docs + plugin API GA)              integration

Post-GA (still untagged; no release mechanics until a deliberate later decision):

Phase 14 (selection + feedback)              track H
  ├── Phase 15 Data providers                track B follow-on
  └── Phase 16 Profiling                     spine
Phase 17 (scheduling + timing cache)         spine
  ├── Phase 18 Spawn + protocol cost         track I
  └── Phase 19 CI gates + sharding           track J
Phase 20 (validation + ecosystem)            integration
```

The post-GA ordering is deliberate: user-facing selection tools first (Phase 14 also establishes the temp-dir state-file convention later phases reuse), profiling before any performance work so Phases 17 and 18 optimise measured problems, and published benchmarks last so they describe the improved runner.

## The serial spine

Phase 0, then 1, then 5a, then 5b, then 7. These phases define the toolchain, the shared vocabulary of types, lifecycle semantics, the process/wire architecture, and the extension contract. Each is a global architecture decision; two agents making them concurrently produces two frameworks. One owner runs the spine, and each spine phase ends with an interface-freeze RFC in `docs/rfcs/` that unblocks the fan-out tracks listed above.

RFC registry:

- RFC-001: result and event model (ends Phase 1)
- RFC-002: lifecycle and harness scopes (written before 5a implementation)
- RFC-003: wire protocol (written before 5b implementation)
- RFC-004: public plugin API surface (ends Phase 7, GA in Phase 13)

## Coordination rules for parallel tracks

1. The handoff artifact is a merged RFC plus frozen interfaces on `main`, never a description in a task prompt. A track agent starts from a commit containing the interfaces it consumes.
2. One component, one owner. No two agents write to the same `src/<Component>/` directory in the same window. If your track needs a change in another component, file the proposed interface change back to the spine owner; do not edit it in place.
3. deptrac is the tripwire. If your track "just needs one class" from another component, CI will fail. That is intended; escalate rather than work around it.
4. Fixture suites under `tests/Fixture/` are append-only during fan-out: add directories, never modify existing ones (other tracks' golden tests depend on them).
5. Track agents never touch `src/Core/`.
6. Convergence reviews happen before 5b starts and before Phase 11: a pass over merged tracks for convention drift (naming, error message style) while it is cheap.

## Agent briefing template

When spinning off a phase agent, give it exactly:

- this file (`docs/plan/README.md`)
- its phase file (`docs/plan/phase-XX-*.md`)
- the PRD sections named at the top of the phase file
- the RFC(s) named in its "Unblocked by" column
- write access limited to the directories its phase file lists under Deliverables, plus `tests/`
