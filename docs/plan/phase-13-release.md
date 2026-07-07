# Phase 13: documentation and plugin API GA

| | |
|---|---|
| **Track** | integration (serial) |
| **Unblocked by** | all previous phases |
| **PRD sections** | 15 (roadmap), 16 (success metrics) |
| **Writes to** | `docs/`, `README.md`, `CHANGELOG.md` |

## Goals

Documentation a newcomer can adopt from, and the plugin API's stability promise. No version tag: the project stays pre-release until a deliberate later decision, so this phase creates the BC surface without dating it. Watch mode, which the PRD section 15 roadmap lists in the same milestone, is delivered by [phase-12b-watch.md](phase-12b-watch.md) and is not in scope here beyond documenting it.

## Key tasks

- Documentation structure under `docs/`: getting started, configuration reference generated from the builder's signatures, attribute reference, plugin author guide, migration-from-PHPUnit conceptual guide.
- Remove `@experimental` from the RFC-004 surface after a review pass.
- CHANGELOG backfill under an Unreleased heading.
- Explicitly out of scope, deferred until a release is actually wanted: tagging, pushing, Packagist publication, release workflow, phar distribution. Nothing in this phase or the phases after it may create a tag.
- Benchmarks are also out of scope here; they belong to [phase-20-validation-ecosystem.md](phase-20-validation-ecosystem.md) so published numbers describe the improved runner.

## Deliverables

The documentation set and the GA'd plugin API on `main`, with the README stating pre-release status honestly. Deliberately not a deliverable: any tag or published package.

## Design decisions

- Docs tooling: plain markdown in-repo; a site generator is a later decision.
- GA without a tag is intentional. The semver promise starts when the first tag is cut; until then the GA'd surface is a statement of intent that lets plugin authors build without betting on `@internal` classes.

## Risks

Docs drift between GA and the eventual first tag. Mitigation: the CHANGELOG's Unreleased section is the single accumulation point, and phases 14 onward append to it as they land.

## Validation

- An outside reader follows getting-started on a fresh project unaided.
- The flaky-quarantine and mutation-prototype plugins from the PRD acceptance test build against the GA API without patching core.
