# Coverage JSON schema

Greenlight's JSON coverage export is produced by
`Greenlight\Coverage\Export\JsonExporter` and imported by
`JsonExporter::import()`.

It is also used by coverage diffing:

```sh id="x3l9w8"
greenlight coverage:diff --baseline=baseline.json --current=current.json
```

The schema is versioned and stable.

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

Schema version.

For this revision, the value is always the integer `1`.

Readers must reject any other value.

### files

An object keyed by absolute file path.

Entries are sorted by path.

`files` is always an object, including in an empty report:

```json id="g6nqcx"
{}
```

It is never an array.

### files.*.covered

A sorted list of unique positive line numbers that executed at least once.

### files.*.uncovered

A sorted list of unique positive line numbers that are executable but did not
execute.

This list is disjoint from `covered`.

### files.*.percentage

The file coverage percentage:

```text id="u0r5au"
covered / (covered + uncovered) * 100
```

The value is rounded to two decimal places.

A file with no executable lines reports `100.0`.

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

The value is rounded to two decimal places.

An empty report has `100.0` coverage because there are no executable lines to
miss.

## Semantics

The format stores line coverage only.

Lines reported by the coverage driver as dead or unreachable code are excluded.
They appear in neither `covered` nor `uncovered`.

A line is covered if any test in the run executed it. The format does not store
hit counts.

`percentage` values and the `totals` object are derived from the line lists.
`import()` recomputes them and ignores the stored values, so editing the
percentages cannot change the imported coverage.

The file is UTF-8 JSON, written with unescaped slashes and a trailing newline.

## Versioning

Version `1` may gain additive fields.

Readers must ignore unknown keys.

Any change to the meaning or shape of existing fields requires a new `v` value.

Per-test coverage mapping may be added later as an opt-in extension. If added,
it will use an additive key and be documented separately.
