# Test discovery manifest

`list-tests --format=json` writes one versioned JSON test discovery manifest.
It discovers the selected tests and does not execute them.

The version 1 JSON Schema is at
[resources/schema/test-manifest-v1.schema.json](../../resources/schema/test-manifest-v1.schema.json).

## Selection and order

The command accepts the normal test selectors. These selectors include suite,
group, test ID, exclusion, seed, previous-failure, and shard selectors.

The `tests` array is in plan order. The `order.tests` value is `plan`.
The manifest does not contain completion order because no test executes.
Thus, `order.completion` is `not-applicable`.

The `order.seed` value contains the resolved seed. Its value is `null` for an
unseeded plan. The `shard` value is `null` when no shard is selected. Otherwise,
it is an object with a one-based `index` and the total `count`. The values
**MUST** satisfy `1 <= index <= count`.

## Test entries

Each test entry has these identity and source fields:

- `id`: The complete stable test ID.
- `class`: The test class.
- `method`: The test method.
- `dataSetKey`: The normalized data-set key, or `null`.
- `source.file`: The absolute path of the file that declares the test method.
- `source.line`: The test method declaration line.
- `groups`: All groups declared for the test, sorted by name.
- `suites`: Each configured suite whose path contains the test class file,
  sorted by name.

For an inherited method, `source` identifies the parent method's declaration.
Suite membership uses the test class file, which can be different.

A labeled data row or provider key keeps its printable label in `dataSetKey`.
An integer key uses `#<value>`. Greenlight hashes an empty or nonprintable key.

Group selection filters tests. It does not remove other groups from a selected
test entry.

Each entry also contains this execution metadata:

| Field | Meaning |
| --- | --- |
| `skip.present` | Whether the test has a skip reason or condition. Discovery does not evaluate the condition. |
| `skip.condition` | The skip condition class, or `null`. |
| `retry.additionalAttempts` | The configured number of retries, or `0` when none is configured. |
| `retry.onlyOn` | The exception class that restricts retries, or `null`. |
| `timeoutSeconds` | The test time budget in seconds, or `null` when no budget is configured. |
| `captureOutput` | Whether output capture is enabled for the test. |
| `noExpectations` | Whether the test permits zero expectations. |
| `resources` | The required resource names, sorted by name. |
| `isolated` | Whether the test requires a fresh worker process in a process-pool run. |
| `allowParallel` | Whether each selected test or data set forms a separate pooled scheduling unit. |

The manifest does not contain skip reasons or condition arguments. It also
does not contain closures, plugin instances, or internal wire payloads.

## Streams and exit codes

Standard output contains only the JSON document and its final newline.
Greenlight writes warnings and diagnostics to standard error.

The command uses these exit codes:

- `0`: Discovery succeeded. The `tests` array can be empty.
- `1`: Configuration or discovery failed. Standard output is empty.
- `64`: Command-line use is invalid. Standard output is empty.

## Compatibility

The top-level `version` value selects the complete manifest schema.
Consumers **SHOULD** validate the document against the schema for that version.

Greenlight **MAY** add optional fields in one version. Consumers **MUST** ignore
unknown fields.

A removed field, renamed field, new required field, changed type, or changed
meaning requires a new version and schema file. A closed enum extension also
requires a new version.
