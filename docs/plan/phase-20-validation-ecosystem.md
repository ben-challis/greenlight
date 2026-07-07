# Phase 20: platform validation and ecosystem proof

| | |
|---|---|
| **Track** | post-GA, integration (parallelisable: the three proofs are independent) |
| **Unblocked by** | Phases 17 and 18 (benchmark numbers should describe the improved runner, not the one being replaced) |
| **PRD sections** | 16 (success metrics), 17 (risks) |
| **Writes to** | `.github/workflows/`, `tools/`, `docs/`, `tests/` |

## Goals

Close the open claims the project currently cannot back: Greenlight is fast relative to PHPUnit plus ParaTest (no numbers exist), and the mutation-testing story is known rather than assumed. Windows validation was cut from the phase by decision: the platform is not a target for now, the README requirements already scope support honestly, and the tcp fallback remains documented as unvalidated.

## Key tasks

- The benchmark harness. A `tools/benchmark` script generates synthetic suites in the shapes that matter (many small fast classes, few slow classes, one giant data-set class, a mixed realistic profile), runs each under Greenlight and under PHPUnit with and without ParaTest at matched worker counts, and reports wall time, peak memory, and startup overhead. Results go in `docs/benchmarks.md` with the generation parameters so anyone can reproduce them, and the README only ever cites numbers that document can back. If the numbers are unflattering somewhere, that goes in the document too, with the Phase 16 profile explaining why.
- Mutation testing spike. Time-boxed investigation of running Infection against a Greenlight-tested project: its custom framework adapter surface, whether the junit and coverage exports we already produce satisfy it, and what an `--filter`-driven per-mutant invocation costs given our startup profile. Output is a written recommendation (build an adapter, contribute upstream, or document as unsupported for now), not necessarily code.

## Deliverables

`docs/benchmarks.md` with reproducible numbers and a decision record for mutation testing.

## Design decisions

- Benchmarks are generated, not curated. Hand-picked real projects flatter whoever picks them; synthetic shapes with published generators are reproducible and name the trade-offs explicitly.
- Publish losses as well as wins. A benchmark document that only contains victories reads as marketing and ages badly; the numbers exist to direct engineering, and the README inherits only what survives.
- The mutation spike is deliberately allowed to conclude "not now". Mutation testing exercises the runner's per-invocation overhead harder than anything else; if the spike says the startup cost makes it impractical before Phase 18's discovery cache matured, that conclusion feeds the roadmap instead of forcing a half-working adapter.

## Dependencies

Phases 17 and 18 first, so published numbers describe the shipped scheduler and spawn path.

## Risks

Benchmark numbers measured on one developer machine overfit to it. Mitigation: the document publishes the generation parameters and the machine profile, and CI re-runs the script on a small parameter set so the harness itself cannot rot even though CI numbers are not published.

## Validation

This phase is itself validation. Its own check: the benchmark script runs end to end in CI on a small parameter set so it cannot rot.
