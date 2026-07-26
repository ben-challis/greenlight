# Decision record: Infection support

Status: deferred until Greenlight has a per-test coverage map.

## Requirement

Infection needs the tests that cover each mutated line.

Its adapter can then run this loop:

1. Run the suite once and collect per-test coverage.
2. For each mutant, find the tests that cover the changed line.
3. Run only those tests.
4. Treat a run with a test failure or test error as a killed mutant.

This process keeps mutation tests within a practical limit. Infection can run
the complete suite for every mutant, but that integration is too slow.

## Current Greenlight support

Greenlight already supplies these parts of the process:

* Repeatable `--test-id` arguments run exact test IDs. Thus, partial text and
  wildcard patterns do not cause selection ambiguity.
* The discovery cache reduces the cost of repeated commands.
* JUnit output provides test locations.
* Machine-readable reporters and exit codes provide mutant run results.
* Coverage capture and export exist for line coverage of a complete run.

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

Per-test coverage maps need changes below the adapter layer.

The collector **MUST** record coverage for each test. It can start and stop
coverage around each test. As an alternative, it can calculate the difference
for each test.

Workers **MUST** send that data back to the orchestrator.

The merge model **MUST** preserve the relation between test IDs and covered
lines. It **MUST NOT** reduce all data to one file-level line set.

The export layer **MUST** then write a format Infection can consume.

## Adapter shape

After per-test coverage exists, a small external package can add Infection
support. The package contains an Infection `TestFrameworkAdapter` and a factory.

The adapter has these responsibilities:

* run Greenlight one time for per-test coverage and JUnit output
* map mutated lines to Greenlight test IDs
* call Greenlight with `--test-id` for those test IDs
* use the result to classify each mutant as killed or survived

The proposed design requires no other runner changes.

## Decision

Defer the adapter until a per-test coverage map exists.

Before that work is complete, the adapter **MUST** run the complete suite for
each mutant. This process is too slow for a useful Greenlight integration.
