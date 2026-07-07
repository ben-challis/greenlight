# Coverage JSON schema

Greenlight's own coverage export format, produced by `Greenlight\Coverage\Export\JsonExporter` and read back by `JsonExporter::import()`. It is the format consumed by baseline diffing (`greenlight coverage:diff --against=baseline.json`), so it is a stable, versioned schema.

## Document shape

```json
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

- `v`: schema version. Always the integer `1` for this revision. Readers must reject any other value.
- `files`: an object keyed by absolute file path, sorted by path. Always an object, never an array, including when empty (`{}`).
- `files.*.covered`: sorted list of unique positive line numbers that executed at least once.
- `files.*.uncovered`: sorted list of unique positive line numbers that are executable but never executed. Disjoint from `covered`.
- `files.*.percentage`: `covered / (covered + uncovered) * 100`, rounded to two decimal places. A file with no executable lines reports `100.0`.
- `totals.files`: number of entries in `files`.
- `totals.coveredLines`: sum of covered line counts across all files.
- `totals.executableLines`: sum of executable (covered plus uncovered) line counts across all files.
- `totals.percentage`: `coveredLines / executableLines * 100`, rounded to two decimal places. An empty report is `100.0`: there is nothing to miss.

## Semantics

- Line coverage only. Lines a driver reports as dead code (unreachable) are excluded entirely; they appear in neither list.
- A line covered by any test in the run is covered, regardless of how many tests hit it. Hit counts are not preserved.
- `percentage` and everything under `totals` are derived data. `import()` recomputes them from the line lists and ignores the stored values, so a hand-edited document cannot claim coverage its line lists do not support.
- Encoding is UTF-8 JSON with unescaped slashes and a trailing newline.

## Versioning

Additive fields may appear within version `1`; readers must ignore unknown keys. Any change to the meaning or shape of existing fields bumps `v`.

Per-test coverage mapping (which tests execute which lines) is planned as an opt-in extension and will be introduced as an additive key with its own documentation before it ships.
