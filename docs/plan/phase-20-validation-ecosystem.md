# Phase 20: platform validation and ecosystem proof

| | |
|---|---|
| **Track** | post-GA, integration (parallelisable: the three proofs are independent) |
| **Unblocked by** | Phases 17 and 18 (benchmark numbers should describe the improved runner, not the one being replaced) |
| **PRD sections** | 16 (success metrics), 17 (risks) |
| **Writes to** | `.github/workflows/`, `tools/`, `docs/`, `tests/` |

## Goals

Close the three open claims the project currently cannot back: Windows works (the tcp fallback has never run on a real Windows box), Greenlight is fast relative to PHPUnit plus ParaTest (no numbers exist), and the mutation-testing story is known rather than assumed.

## Key tasks

- Windows validation. A `windows-latest` CI job runs the full self-hosted suite. Expected trouble spots are enumerated up front so failures are diagnoses, not surprises: the tcp socket fallback path (unix sockets exist on modern Windows but the temp-dir path length limits bite), path separator assumptions in discovery and the proxy cache key, `proc_open` argument quoting, and the ANSI detection for the tty reporter under Windows terminals. Whatever cannot be made green gets documented as a known limitation rather than left unknown.
- The benchmark harness. A `tools/benchmark` script generates synthetic suites in the shapes that matter (many small fast classes, few slow classes, one giant data-set class, a mixed realistic profile), runs each under Greenlight and under PHPUnit with and without ParaTest at matched worker counts, and reports wall time, peak memory, and startup overhead. Results go in `docs/benchmarks.md` with the generation parameters so anyone can reproduce them, and the README only ever cites numbers that document can back. If the numbers are unflattering somewhere, that goes in the document too, with the Phase 16 profile explaining why.
- Mutation testing spike. Time-boxed investigation of running Infection against a Greenlight-tested project: its custom framework adapter surface, whether the junit and coverage exports we already produce satisfy it, and what an `--filter`-driven per-mutant invocation costs given our startup profile. Output is a written recommendation (build an adapter, contribute upstream, or document as unsupported for now), not necessarily code.

## Deliverables

A Windows CI job (green or with documented limitations), `docs/benchmarks.md` with reproducible numbers, and a decision record for mutation testing.

## Design decisions

- Benchmarks are generated, not curated. Hand-picked real projects flatter whoever picks them; synthetic shapes with published generators are reproducible and name the trade-offs explicitly.
- Publish losses as well as wins. A benchmark document that only contains victories reads as marketing and ages badly; the numbers exist to direct engineering, and the README inherits only what survives.
- The mutation spike is deliberately allowed to conclude "not now". Mutation testing exercises the runner's per-invocation overhead harder than anything else; if the spike says the startup cost makes it impractical before Phase 18's discovery cache matured, that conclusion feeds the roadmap instead of forcing a half-working adapter.

## Dependencies

Phases 17 and 18 first, so published numbers describe the shipped scheduler and spawn path.

## Risks

Windows may surface deep assumptions (socket semantics, path handling) whose fixes ripple through the orchestrator. Mitigation: the job lands as non-required while triaging, with each failure converted into either a fix or a documented limitation before the job becomes required.

## Validation

This phase is itself validation. Its own checks: the benchmark script runs end to end in CI on a small parameter set so it cannot rot, and the Windows job runs on every push to main once required.
