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

The schema version.

For this revision, this value is always the integer `1`.

Readers **MUST** reject all other values.

### files

An object that uses absolute file paths as keys.

Greenlight sorts entries by path.

Absolute keys make a baseline specific to its checkout root. Coverage from two
sources represents the same file only when the keys match exactly. Sources
include worktrees, containers, and machines.

Use a stable mounted path. As an alternative, normalize both documents before
you compare them. Project-relative keys require a new schema version.

`files` is always an object. This rule also applies to an empty report:

```json id="g6nqcx"
{}
```

It is never an array.

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

## Semantics

The format stores line coverage only.

The format excludes lines that the coverage driver identifies as dead or
unreachable code. These lines do not appear in `covered` or `uncovered`.

A line has coverage if one or more tests in the run executed it. The format
does not store hit counts.

The line lists supply the `percentage` values and the `totals` object.
`import()` calculates these values again and ignores the stored values.
Therefore, a percentage edit cannot change the imported coverage.

The file uses UTF-8 JSON, unescaped slashes, and a newline at its end.

## Versioning

Version `1` **MAY** receive additive fields.

Readers **MUST** ignore unknown keys.

Each change to the meaning or shape of an existing field **MUST** use a new
`v` value.

Greenlight **MAY** add per-test coverage maps later as an optional extension.
This extension **MUST** use an additive key and separate documentation.
