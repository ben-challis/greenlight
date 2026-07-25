# Decision record: Infection support

Status: implemented in core; adapter shipped as the separate
`greenlight/infection-adapter` package.

## Decision

Greenlight exposes opt-in per-test line coverage and strict exact-test
selection. The Infection-specific bridge stays outside the core package.

The integration does not run the full suite for every mutant:

1. Infection invokes `greenlight-infection` for one complete initial run.
2. The wrapper enables `--coverage-map` and uses Infection's configured source
   directories as `--coverage-include` paths.
3. Greenlight records which exact test ids covered each source line.
4. The adapter converts that streaming artifact to the PHPUnit coverage XML
   shape Infection 0.34 currently reads.
5. For each covered mutant, the adapter writes the covering ids to an exact-test
   file and invokes Greenlight with `--test-id-file`.
6. Infection's include interceptor replaces the original source with the mutant
   in the wrapper and every spawned Greenlight worker.

There is no full-suite-per-mutant fallback. A mutation with no covering tests
uses the adapter's no-tests path.

This is mutation-test selection, not a general CI strategy. Projects should
continue to run their complete Greenlight suite in CI.

## Package boundary

Core owns the capabilities that have value beyond Infection:

* coverage-driver windows around individual tests
* the test-to-line domain model and versioned JSONL artifact
* bounded worker-to-orchestrator transport and merge storage
* strict `--test-id` and `--test-id-file` selection

`packages/infection-adapter` owns Infection's
`TestFrameworkAdapterFactory`, command construction, mutation include
interception, and conversion to Infection's current XML input. Infection is not
a runtime dependency of `greenlight/greenlight`.

Install and run it with:

```sh
composer require --dev infection/infection greenlight/infection-adapter
vendor/bin/infection --test-framework=greenlight
```

Infection locates `greenlight.php` through the adapter name and locates the
package's `greenlight-infection` executable through Composer.

## Coverage semantics

Greenlight starts and stops the selected driver for every test. Both pcov and
Xdebug produce a fresh window, so mappings are observations for that test, not
deltas from an ever-growing process map.

The window includes test construction, hooks, the method body, retries, and
last-test class teardown. Consequences:

* Each data row is a distinct rendered test id.
* All retry attempts are unioned into that id's mapping.
* A failed or errored test still contributes the coverage collected before its
  outcome.
* A skipped test remains in the test table and normally has no coverage rows.
* Class teardown coverage is attributed to the final test in that class,
  matching Greenlight's existing teardown-failure attribution.
* A worker crash during a test produces no completed mapping for that test.

The artifact is published only for a successful, uninterrupted run and marks
itself `complete`. Infection's initial suite must pass before mutation testing
continues.

`#[CoverageIgnore]` and the supported PHPUnit ignore comments are applied to
both aggregate and per-test data. Dead code is omitted. Absolute source paths
are preserved in the Greenlight artifact; the adapter requires Infection's
source directories to resolve inside the project root when it builds relative
XML paths.

## Cost and memory

Per-test mapping is off by default. It adds one driver start/stop pair per test,
normalisation work, socket traffic, and spool I/O. Aggregate-only coverage keeps
its existing slice-sized windows and sends no per-test protocol messages.

Workers send at most 50,000 covered lines in a `coverage` frame. The
orchestrator writes the many-to-many relation to an append-only temporary spool.
Memory is bounded primarily by the execution plan, source-file table, and the
union of attributed lines rather than every test-line pair. The adapter
similarly spools XML fragments per source file instead of retaining the whole
relation.

## Exact-test contract

`--filter` remains the user-friendly substring/wildcard selector. It is not the
adapter API.

`--test-id` and `--test-id-file` are case-sensitive exact selectors using the
same rendered id stored in the artifact. Unknown or stale ids fail loudly after
discovery. The file form trims blank lines, removes duplicates, and avoids
command-line length limits.

The adapter creates a different exact-test file for each mutation hash. Rendered
ids containing line breaks cannot be represented by the v1 line-oriented file
and are rejected explicitly.

## Compatibility

Ordinary runs, aggregate coverage exports, reporters, and configuration remain
unchanged unless per-test coverage is enabled. The worker protocol moved to
version 2 because it gained the `coverage` message, but that protocol is
internal and orchestrator and workers always come from the same installation.

The Greenlight artifact has its own version and schema. The adapter consumes
version 1 and converts it at its package boundary, insulating core from
Infection's current PHPUnit XML implementation.

## Conservative local use

The artifact can support other impact-aware local tools, but Greenlight does not
enable changed-file selection by default. Any future watch integration should
fall back to the configured selection whenever attribution is missing, stale,
or ambiguous. It must remain optional and must not be presented as a
replacement for full-suite CI.

## Remaining product decisions

The implemented contract intentionally leaves these future choices open:

* whether a later exact-test file version should support JSON-encoded ids,
  including line breaks
* whether to retain and validate artifact fingerprints across separate runs
* whether a conservative impact-aware watch mode is worth its state and
  invalidation complexity
* whether measured real-suite overhead justifies configurable line-chunk sizes
* how the external adapter's pre-release-compatible Greenlight constraint
  should move through the first stable release

None is required for the current Infection workflow.
