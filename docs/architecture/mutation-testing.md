# Decision record: Infection support

Status: deferred until Greenlight has a per-test coverage map.

## Requirement

The planned Infection adapter needs the tests that cover each mutated line.

Its adapter can then run this loop:

1. Run the suite one time.
2. During the run, collect coverage for each test.
3. For each mutant, find the tests that cover the changed line.
4. Run only those tests.
5. Treat a run with a test failure or test error as a killed mutant.

This process avoids a complete suite run for each mutant. Greenlight defers an
adapter that repeats the complete suite until per-test selection is available.

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
which lines did the run cover?
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
support. The package implements Infection
[`TestFrameworkAdapter` and `TestFrameworkAdapterFactory`](https://github.com/infection/abstract-testframework-adapter).

The adapter has these responsibilities:

* run Greenlight one time for per-test coverage and JUnit output
* map mutated lines to Greenlight test IDs
* call Greenlight with `--test-id` for those test IDs
* use the result to classify each mutant as killed or survived

## Decision

Defer the adapter until a per-test coverage map exists.

Without per-test coverage, the proposed adapter would run the complete suite for
each mutant. This does not meet the selected-test execution goal.
