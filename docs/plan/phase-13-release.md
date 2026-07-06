# Phase 13: documentation, plugin API GA, release preparation

| | |
|---|---|
| **Track** | integration (serial) |
| **Unblocked by** | all previous phases |
| **PRD sections** | 15 (roadmap), 16 (success metrics) |
| **Writes to** | `docs/`, `.github/workflows/`, `README.md`, `CHANGELOG.md` |

## Goals

v1.0: documentation a newcomer can adopt from, the plugin API's semver promise, release mechanics. Watch mode, which the PRD section 15 roadmap lists in the same milestone, is delivered by [phase-12b-watch.md](phase-12b-watch.md) and is not in scope here beyond documenting it.

## Key tasks

- Documentation structure under `docs/`: getting started, configuration reference generated from the builder's signatures, attribute reference, plugin author guide, migration-from-PHPUnit conceptual guide.
- Remove `@experimental` from the RFC-004 surface after a review pass.
- Release workflow: tagging, Packagist, signed phar as a stretch goal.
- CHANGELOG backfill.
- Publish the PRD section 16 benchmark suite (vs PHPUnit plus paratest on a public reference project).

## Deliverables

The v1.0.0 tag.

## Design decisions

- Docs tooling: plain markdown in-repo for v1; a site generator is post-v1.
- Phar distribution is a stretch goal, never a gate.

## Risks

Benchmark claims that do not hold. The PRD promises published benchmarks; if 2x is not met, the number in the README is the measured one, whatever it is.

## Validation

- An outside reader follows getting-started on a fresh project unaided.
- The flaky-quarantine and mutation-prototype plugins from the PRD acceptance test build against the GA API without patching core.
