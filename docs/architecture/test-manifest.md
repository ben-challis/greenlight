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
- `source.file`: The absolute declaring file.
- `source.line`: The test method declaration line.
- `groups`: All groups declared for the test, sorted by name.
- `suites`: Each configured suite whose path contains the test class file.

A labeled data row or provider key keeps its printable label in `dataSetKey`.
An integer key uses `#<value>`. Greenlight hashes an empty or nonprintable key.

Group selection filters tests. It does not remove other groups from a selected
test entry.

Each entry also contains this execution metadata:

- `skip.present` and `skip.condition`
- `retry.additionalAttempts` and `retry.onlyOn`
- `timeoutSeconds`
- `captureOutput` and `noExpectations`
- `resources`
- `isolated` and `allowParallel`

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
