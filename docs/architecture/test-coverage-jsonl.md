# Per-test coverage JSONL schema

`CoverageBuilder::perTest()` and `--coverage-map` write the source lines covered
by each test as JSONL.

The JSON Schema for version 1 is
[resources/schema/test-coverage-jsonl-v1.schema.json](../../resources/schema/test-coverage-jsonl-v1.schema.json).

Per-test coverage has a separate format from the `jsonl` reporter and the
aggregate coverage JSON export.

## Lifecycle

During a run, Greenlight appends coverage records to a temporary file. It moves
the completed artifact to the target path after the suite passes. A failed,
empty, leaking, or interrupted run leaves the target unchanged, including any
complete artifact already there.

A consumer that starts Greenlight in another process should use a different
target for each run or check the `runId` before reading an existing file.

Per-test coverage requires a coverage driver and at least one include path. If
pcov or Xdebug coverage mode is unavailable, the run fails. Aggregate coverage
only warns in the same situation.

## Record envelope

Every line is a UTF-8 JSON object with two common fields:

* `v` is the schema version, currently `1`
* `type` identifies the record

Lines end in `\n`. Version 1 may add fields and record types. Readers must
ignore unknown fields and skip unknown record types. A change to an existing
field requires a new version.

## `meta`

The first line describes the artifact:

```json
{"v":1,"type":"meta","root":"/project","runId":"a17f...","complete":true}
```

`root` is the absolute working directory. `runId` identifies the Greenlight
run. Version 1 only publishes finished artifacts, so `complete` is always
`true`.

## `test`

There is one `test` record for each planned test, in plan order:

```json
{"v":1,"type":"test","test":0,"id":{"class":"App\\Tests\\PriceTest","method":"totals","dataSetKey":"two units"},"renderedId":"App\\Tests\\PriceTest::totals[two units]","file":"/project/tests/PriceTest.php"}
```

`test` is a zero-based ordinal used by other records in the same artifact. `id`
is the structured test ID. `renderedId` is the exact value accepted by
`--test-id` and `--test-id-file`. `file` is the test source path found during
discovery.

Ordinals are local to an artifact. Consumers should persist the structured or
rendered ID instead.

## `coverage`

A `coverage` record assigns covered lines to a test:

```json
{"v":1,"type":"coverage","test":0,"file":"/project/src/Price.php","lines":[12,13,17]}
```

`lines` contains unique positive line numbers and no more than 50,000 entries.
A test and source file may have more than one record. Readers must combine their
line lists.

Each data row has its own test ordinal. Coverage from retries is already
combined. A test with no mapped lines has no `coverage` record.

## `source`

A `source` record lists executable lines from the aggregate coverage result:

```json
{"v":1,"type":"source","file":"/project/src/Price.php","covered":false,"lines":[21,22]}
```

`covered` states whether the listed lines ran during the suite. The covered and
uncovered records reconstruct the aggregate line map without a separate
coverage JSON export.

## `unattributed`

An `unattributed` record lists aggregate covered lines that belong to no
completed test window:

```json
{"v":1,"type":"unattributed","file":"/project/src/Bootstrap.php","lines":[7,8]}
```

This includes coverage from the orchestrator, bootstrap code, child Greenlight
processes, and a worker that stopped before completing its current test.
Readers must not assign these lines to every test.

## Paths and ignored lines

Source paths are the absolute paths returned by the coverage driver. Test paths
come from discovery.

`#[CoverageIgnore]` and the supported ignore comments apply before Greenlight
writes the artifact. Ignored lines do not appear in `coverage`, `source`, or
`unattributed` records. Dead code is omitted as it is from aggregate coverage.

## Ordering and storage

Version 1 writes records in this order:

1. `meta`
2. every `test` record
3. `coverage` records in arrival order
4. `source` and `unattributed` records in aggregate file order

Readers should use record fields rather than rely on this order, apart from the
metadata and test table appearing before coverage records.

A per-test map can be much larger than aggregate coverage. Greenlight and the
Infection adapter read and write it one line at a time.

## Impacted watch lifecycle

Standard watch mode does not collect per-test coverage. Add
`--watch-impacted` to use the map for impacted selection.

Impacted watch publishes the map only after a successful complete selected
run. A selective run does not replace it. The next file change uses a complete
run to refresh the stale map.

The impact reader streams the artifact for each stable change batch. It keeps
the test table and records for changed lines in memory.

The reader selects a line only when the aggregate map marks it covered and a
completed test owns it. Uncovered, missing, or unattributed lines cause a
complete selected run.
