# Per-test coverage JSONL schema

Greenlight's opt-in per-test coverage artifact maps exact test identities to
covered source lines. It is enabled with
`CoverageBuilder::perTest()` or `--coverage-map`.

The machine-readable JSON Schema for version 1 is
[resources/schema/test-coverage-jsonl-v1.schema.json](../../resources/schema/test-coverage-jsonl-v1.schema.json).

This is not the `jsonl` reporter stream and not the aggregate coverage JSON
export. The three formats have separate schemas and compatibility contracts.

## Lifecycle

Greenlight writes observations to a temporary append-only spool during the run,
then publishes the target atomically after the suite succeeds. Interrupted,
empty, leaking, or unsuccessful runs do not publish a new artifact. If a target
already exists, that older complete file remains until a later successful run
replaces it; consumers coordinating separate processes should use a unique
target or verify `runId`.

Per-test mode requires a coverage driver and at least one include path. Missing
pcov or Xdebug coverage mode is a run failure rather than the soft warning used
by aggregate-only coverage.

## Record envelope

Every line is a UTF-8 JSON object with:

* `v`: schema version, currently `1`
* `type`: record type

Lines end in `\n`. Version 1 may gain keys and record types additively.
Consumers must ignore unknown keys and skip unknown record types. Changing or
removing an existing field requires a new version.

## `meta`

The first record:

```json
{"v":1,"type":"meta","root":"/project","runId":"a17f...","complete":true}
```

`root` is the absolute project working directory. `runId` ties the artifact to
the Greenlight run. Version 1 only publishes complete artifacts, so `complete`
is always `true`.

## `test`

One record for every planned test, in execution-plan order:

```json
{"v":1,"type":"test","test":0,"id":{"class":"App\\Tests\\PriceTest","method":"totals","dataSetKey":"two units"},"renderedId":"App\\Tests\\PriceTest::totals[two units]","file":"/project/tests/PriceTest.php"}
```

`test` is a zero-based artifact-local ordinal used by coverage records. `id` is
the structured stable identity. `renderedId` is the exact string accepted by
`--test-id` and `--test-id-file`. `file` is the discovered test source path.

An ordinal is local to one artifact and must not be persisted as a test
identity.

## `coverage`

A chunk of covered source lines attributed to one test:

```json
{"v":1,"type":"coverage","test":0,"file":"/project/src/Price.php","lines":[12,13,17]}
```

Records contain covered lines only. Lists are non-empty positive line numbers
and contain at most 50,000 entries. A test and file may have multiple chunks.
Consumers take the union.

Data rows have separate test ordinals. Retries are already unioned. Empty
mappings have no `coverage` record.

## `source`

Aggregate executable-line information:

```json
{"v":1,"type":"source","file":"/project/src/Price.php","covered":false,"lines":[21,22]}
```

`covered` says whether the listed executable lines ran anywhere in the suite.
Covered and uncovered chunks together reconstruct the aggregate line map
without requiring the separate coverage JSON export.

## `unattributed`

Aggregate covered lines that no completed test window attributed:

```json
{"v":1,"type":"unattributed","file":"/project/src/Bootstrap.php","lines":[7,8]}
```

Typical causes are orchestrator code, relay coverage from spawned Greenlight
processes, bootstrap work outside a test window, or a worker that did not
complete its current test. These lines must not be assigned to every test.

## Paths and ignored lines

Source paths are absolute driver paths. Test source paths are the paths recorded
by discovery. `#[CoverageIgnore]` and supported ignore comments are applied
before publication, so ignored lines occur in none of `coverage`, `source`, or
`unattributed`. Dead-code driver statuses are omitted as they are in aggregate
coverage.

## Ordering and storage

The stable order is:

1. `meta`
2. all `test` records
3. zero or more `coverage` records in arrival order
4. `source` and `unattributed` records in aggregate file-path order

Consumers should use the semantic keys rather than depend on ordering beyond
`meta` and the test table preceding coverage in version 1.

The relation can be much larger than aggregate coverage. Consumers should parse
line by line; Greenlight and its Infection adapter both spool it rather than
loading all test-line pairs into memory.
