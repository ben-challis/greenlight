# Decision record: mutation testing via Infection

Status: investigated, deliberately deferred. Revisit when per-test coverage mapping ships.

## What Infection needs from a test framework

Infection's custom adapter surface (`infection/abstract-testframework-adapter`) asks an adapter to do two jobs: translate Infection's execution requests into framework commands, and report test locations. Around that contract, the loop that makes mutation testing tractable is: one initial run collecting coverage with per-test attribution (which tests cover which lines), then, for each mutant, run only the covering tests and treat a failure as a kill. Existing adapters (PHPUnit, Codeception, PhpSpec) all feed Infection per-test coverage in PHPUnit's XML coverage format plus junit test locations.

## What Greenlight has today

- Test subset execution: `--filter` with exact-id selection is precisely the per-mutant invocation an adapter needs, and the discovery cache keeps repeated invocations cheap (roughly 0.15s of fixed cost per run on a medium suite).
- junit export for test locations.
- Coverage collection with five export formats and incremental merge.
- A proven proof-of-concept loop: the acceptance suite contains a working mutation prototype built on exit codes and the plain report, with kills attributed to specific tests.

## The blocker

Greenlight's coverage map is line-to-count, with no per-test attribution. Without knowing which tests cover a mutated line, an adapter would have to run the full suite per mutant, which turns minutes of mutation testing into hours and misses the entire point of the coverage-directed loop. Per-test attribution is the one genuinely missing capability, and it is not adapter glue: it changes the collector (start/stop or delta per test), the wire format, and the merge model.

## Decision

Defer. Per-test coverage mapping is already on the roadmap for its own reasons (watch mode's affected-test selection is designed around it), and it is the prerequisite here too. Building it for the adapter alone would invert priorities; building the adapter without it would ship something misleadingly slow.

When per-test mapping exists, the adapter is a small external package (`TestFrameworkAdapter` plus factory): initial run exports per-test coverage in the format Infection consumes plus junit, per-mutant runs use `--filter` with the covering test ids, exit codes already distinguish kill from survive. Nothing else in the engine needs to change, which is the plugin-architecture claim doing its job.
