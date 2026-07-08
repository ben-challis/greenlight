# Decision record: Infection support

Status: deferred until Greenlight has per-test coverage mapping.

## Requirement

Infection needs to know which tests cover each mutated line.

Its adapter can then run this loop:

1. Run the suite once and collect per-test coverage.
2. For each mutant, find the tests that cover the changed line.
3. Run only those tests.
4. Treat a failing run as a killed mutant.

That keeps mutation testing bounded. Running the whole suite for every mutant is
functionally possible, but not a useful integration.

## Current Greenlight support

Greenlight already has the pieces around the edge of that loop:

* `--filter` can run exact test ids, so per-mutant test selection is available.
* Discovery is cached, keeping repeated invocations cheap.
* JUnit output provides test locations.
* Machine-readable reporters and exit codes provide mutant run results.
* Coverage collection and export already exist for whole-run line coverage.

The missing part is attribution.

Current coverage answers:

```text
which lines were covered by the run?
```

Infection needs:

```text
which tests covered this line?
```

## Required engine work

Per-test coverage mapping needs changes below the adapter layer.

The collector must record coverage per test, either by starting and stopping
coverage around each test or by taking per-test deltas.

Workers must send that data back to the orchestrator.

The merge model must preserve the relationship between test ids and covered
lines, instead of collapsing everything into one file-level line set.

The export layer must then write a format Infection can consume.

## Adapter shape

Once per-test coverage exists, Infection support can be a small external
package: an Infection `TestFrameworkAdapter` plus factory.

The adapter would:

* run Greenlight once for per-test coverage and JUnit output
* map mutated lines to Greenlight test ids
* invoke Greenlight with `--filter` for those ids
* use the result to classify each mutant as killed or survived

No further runner changes should be required.

## Decision

Defer the adapter until per-test coverage mapping exists.

Shipping the adapter before then would mean full-suite execution per mutant,
which is too slow for the integration Greenlight should provide.
