# Coverage JSON schema

`Greenlight\Coverage\Export\JsonExporter` produces the Greenlight JSON coverage
export. `JsonExporter::import()` imports it.

The coverage difference command also uses this format:

```sh id="x3l9w8"
greenlight coverage:diff --baseline=baseline.json --current=current.json
```

The schema has a version. The fields and meanings in version 1 are stable. See
[compatibility](compatibility.md) for the change rules.

## Document shape

```json id="b2emxw"
{
    "v": 1,
    "files": {
        "/project/src/Calculator.php": {
            "covered": [12, 13, 17],
            "uncovered": [21],
            "percentage": 75.0
        }
    },
    "totals": {
        "files": 1,
        "coveredLines": 3,
        "executableLines": 4,
        "percentage": 75.0
    }
}
```

## Fields

### v

The current schema version is the integer `1`. Readers **MUST** reject all
other values.

### files

An object that uses absolute file paths as keys.

Greenlight sorts entries by path.

Each path **MUST** contain valid UTF-8. `JsonExporter::export()` throws
`InvalidArgumentException` if JSON cannot keep the exact path.

Absolute keys make a baseline specific to its checkout root. Coverage from two
sources represents the same file only when the keys match exactly. Sources
include worktrees, containers, and machines.

Use a stable mounted path. For different roots, the [coverage difference
command](../configuration.md#coveragediff) can normalize absolute keys for one
comparison.

Supply both root options. Each file key **MUST** be below the selected root.
Normalization does not change the documents.

`files` is always an object. This rule also applies to an empty report:

```json id="g6nqcx"
{}
```

### files.*.covered

A sorted list of unique positive line numbers. Each line in the list executed
at least once.

### files.*.uncovered

A sorted list of unique positive line numbers. Each line in the list is
executable but did not execute.

This list is disjoint from `covered`.

### files.*.percentage

The file coverage percentage:

```text id="u0r5au"
covered / (covered + uncovered) * 100
```

Greenlight rounds the value to two decimal places.

A file that has no executable lines reports `100.0`.

### totals.files

The number of entries in `files`.

### totals.coveredLines

The total number of covered lines across all files.

### totals.executableLines

The total number of executable lines across all files.

This is the sum of covered and uncovered lines.

### totals.percentage

The total coverage percentage:

```text id="tgplf5"
coveredLines / executableLines * 100
```

Greenlight rounds the value to two decimal places.

An empty report has `100.0` coverage because there are no executable lines to
miss.

## Merge behavior

The coverage merge command reads two or more coverage documents:

```sh id="merge-coverage-json"
greenlight coverage:merge \
    --input=shard-1.json \
    --input=shard-2.json \
    --export=json=coverage.json
```

The result contains the union of the file paths and executable line sets. A
line has coverage if any input identifies it as covered. Thus, an uncovered
line has no covered occurrence in any input.

The merge operation is commutative, associative, and idempotent. Input order,
duplicate inputs, and empty maps do not change the result.

A file can be absent from an input. The result contains that file if a different
input contains it.

The command rejects malformed documents and unsupported versions. Without root
options, each file path must be absolute.

For different roots, give one `--input-root` for each input. Give one
`--project-root` for the output. Greenlight removes each applicable input root
and adds the output root.

The command rejects these root errors:

* The number of input roots differs from the number of inputs.
* A path is outside its applicable input root.
* The same input has different input roots.

Root relocation does not change the schema. The JSON output contains absolute
file paths.

## Semantics

The format stores covered and uncovered line sets, not hit counts.

The format excludes lines that the coverage driver identifies as dead or
unreachable code. These lines do not appear in `covered` or `uncovered`.

A line has coverage if one or more tests in the run executed it.

The line lists supply the `percentage` values and the `totals` object.
`import()` calculates these values again and ignores the stored values.
Therefore, a percentage edit cannot change the imported coverage.

The file uses UTF-8 JSON, unescaped slashes, and a newline at its end.

## Versions

Version `1` **MAY** receive additive fields.

Readers **MUST** ignore unknown keys.

If version 1 defines a field, a change to its definition or shape **MUST** use a
new `v` value.
