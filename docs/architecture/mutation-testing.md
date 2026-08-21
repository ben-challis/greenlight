# Decision record: Infection support

Status: implemented in core, with the adapter in the separate
`greenlight/infection-adapter` package.

## Decision

Greenlight provides per-test line coverage and exact test selection. The
Infection adapter stays outside the core package.

An Infection run works like this:

1. Infection invokes `greenlight-infection` for the initial test run.
2. The wrapper enables `--coverage-map` and passes Infection's source
   directories as `--coverage-include` paths.
3. Greenlight records the source lines covered by each test.
4. The adapter converts that map to the PHPUnit coverage XML read by Infection
   0.34.
5. For each mutant, the adapter writes the covering test ids to a file and
   invokes Greenlight with `--test-id-file`.
6. Infection's include interceptor replaces the original source with the
   mutant in the wrapper and its Greenlight workers.

If no test covers a mutant, the adapter uses Infection's no-tests path. It does
not run the full suite for that mutant.

This selection applies only to mutation runs. Projects should continue to run
their full Greenlight suite in CI.

## Package boundary

Core owns:

* coverage windows around individual tests
* the test-to-line map and its JSONL format
* worker transport and orchestrator storage
* `--test-id` and `--test-id-file`

`packages/infection-adapter` contains the Infection factory, adapter,
include-interceptor wrapper, and JSONL-to-XML converter. Infection is not a
dependency of `greenlight/greenlight`.

Install the adapter alongside Infection:

```sh
composer require --dev infection/infection greenlight/infection-adapter
vendor/bin/infection --test-framework=greenlight
```

Infection finds `greenlight.php` through the adapter name and
`greenlight-infection` through Composer.

## Coverage semantics

The coverage driver starts before each test is constructed and stops after the
test has finished. The window includes hooks, the method body, retries, and
class teardown when the test is last in its class. Each result is a fresh
observation rather than a delta from process-wide coverage.

Data rows have separate test ids. Coverage from every retry attempt is combined
under the same id. A failed or errored test keeps the coverage collected before
its result. A skipped test remains in the test table and usually has no coverage
record.

Class teardown coverage belongs to the final test in the class, matching
Greenlight's existing teardown-failure attribution. If a worker crashes during
a test, that test has no completed coverage record.

Greenlight publishes the map only after a successful, uninterrupted run.
Infection stops if its initial test run fails.

`#[CoverageIgnore]` and the supported PHPUnit ignore comments apply to aggregate
and per-test coverage. Dead code is omitted. The Greenlight map stores absolute
source paths. When the adapter writes Infection's XML, those paths must sit
inside the project root.

## Exact test selection

`--filter` remains the substring and wildcard selector intended for people.
The adapter uses `--test-id` and `--test-id-file`, which match rendered test ids
exactly and case-sensitively.

Greenlight checks every requested id after discovery. An unknown or stale id
fails the run instead of producing an empty selection. The file form ignores
blank lines and duplicate ids, and avoids command-line length limits.

The adapter creates a different id file for each mutation hash. Version 1 of the
file format cannot represent an id containing a line break, so the adapter
rejects one rather than writing an ambiguous file.

## Cost and storage

Per-test coverage is off by default. It adds one coverage-driver start and stop
per test, plus protocol traffic and spool writes. Aggregate coverage keeps its
existing slice-sized windows and sends no per-test messages.

A worker sends at most 50,000 line numbers in one `coverage` message. The
orchestrator writes test-to-line records to a temporary spool. In memory it
keeps the execution plan, ignored lines, and the union of attributed lines. The
adapter also spools its XML fragments by source file.

## Compatibility

Per-test coverage does not change ordinary runs, reporters, or aggregate
coverage exports. Existing configuration behaves as before unless
`CoverageBuilder::perTest()` or `--coverage-map` enables the feature.

The worker protocol is now version 2 because it includes the `coverage`
message. This protocol is internal, and workers always use the same Greenlight
installation as the orchestrator.

The per-test map has its own schema version. The adapter reads version 1 and
converts it to Infection's PHPUnit XML format.

## Watch mode

Per-test coverage is not available in watch mode. A future local test selector
could use the map. It would need to fall back to the configured selection when
the map is missing, stale, or ambiguous. Such a selector would remain optional.
CI should still run the full suite.

## Open questions

* whether a later id-file version should support JSON-encoded ids
* whether maps reused across runs should carry source fingerprints
* whether watch mode should gain conservative test selection
* whether the 50,000-line message limit should be configurable
* which Greenlight version constraint the adapter should use after the first
  stable release
