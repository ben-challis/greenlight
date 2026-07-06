# Phase 3: test discovery

| | |
|---|---|
| **Track** | track B (parallel with Phases 2 and 4) |
| **Unblocked by** | RFC-001 |
| **PRD sections** | 5 (authoring model), 7.1 step 1 (discovery) |
| **Writes to** | `src/Discovery/`, `tests/` |

## Goals

Static discovery of test classes and methods into an execution plan, with zero test code executed.

## Key tasks

- Classmap-based class enumeration honouring configured paths and suites.
- Reflection-based attribute scan.
- Data-set expansion: invoke static providers at plan time in the orchestrator. Providers are the one thing discovery executes; they must be pure, and this is documented and enforced with a time budget per provider.
- Filter engine (group/class/method/path), used by CLI flags and later by watch mode (Phase 12b).
- Deterministic plan ordering with seeded class shuffle.

## Deliverables

`src/Discovery/`; `bin/greenlight list-tests` works end to end against `tests/Fixture/` suites.

## Design decisions

- Providers run at discovery time rather than execution time, so the plan knows every `TestId` up front for distribution and `--rerun-failed`. The cost (providers must not touch expensive resources) is accepted and documented.

## Dependencies

Phases 1 and 2 (needs `Configuration` for paths/suites; can stub it until track A lands).

## Risks

Composer classmap coverage of odd autoloading setups. Mitigation: support explicit path globs as fallback; acceptance fixtures include a PSR-4-violating suite to pin behaviour.

## Validation

Fixture suites with every attribute combination produce byte-identical plans across runs given the same seed.
