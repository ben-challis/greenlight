# Coverage JSON schema

`Greenlight\Coverage\Export\JsonExporter` produces the Greenlight JSON coverage
export. `JsonExporter::import()` imports it.

This format describes aggregate coverage. The
[per-test coverage JSONL format](test-coverage-jsonl.md) preserves which test
covered each line.

The coverage difference command also uses this format:

```sh id="x3l9w8"
greenlight coverage:diff --baseline=baseline.json --current=current.json
```

The schema has a version. Version 1 stores line coverage. Version 2 stores line,
branch, and path coverage. The fields and meanings in both versions are stable. See
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

A version 2 file entry adds function-scoped branch and path identity:

```json id="coverage-json-v2-shape"
{
    "v": 2,
    "files": {
        "/project/src/Decision.php": {
            "covered": [12],
            "uncovered": [13],
            "percentage": 50.0,
            "functions": [{
                "name": "decide",
                "branches": [{
                    "id": 0,
                    "startLine": 12,
                    "endLine": 13,
                    "covered": true,
                    "exits": [{"id": 0, "covered": true}]
                }],
                "paths": [{"branches": [0, 1], "covered": true}]
            }],
            "coveredBranches": 1,
            "branches": 1,
            "branchPercentage": 100.0,
            "coveredPaths": 1,
            "paths": 1,
            "pathPercentage": 100.0
        }
    },
    "totals": {
        "files": 1,
        "coveredLines": 1,
        "executableLines": 2,
        "percentage": 50.0,
        "coveredBranches": 1,
        "branches": 1,
        "branchPercentage": 100.0,
        "coveredPaths": 1,
        "paths": 1,
        "pathPercentage": 100.0
    }
}
```

## Fields

### v

Version `1` identifies line-only coverage. Version `2` identifies a run that
requested and measured Xdebug branch coverage. Readers **MUST** reject all
other values.

Greenlight continues to write version 1 for pcov and ordinary Xdebug runs. It
writes version 2 only when branch coverage is enabled. An empty version 2
document still means that branch measurement occurred.

### files

An object that uses absolute file paths as keys.

Greenlight sorts entries by path.

Each path **MUST** contain valid UTF-8. `JsonExporter::export()` throws
`InvalidArgumentException` if JSON cannot keep the exact path.

Absolute keys make a baseline specific to its checkout root. Coverage from two
sources represents the same file only when the keys match exactly. Sources
include worktrees, containers, and machines.

Use a stable mounted path when possible. For different checkout roots, give
both explicit roots to the difference command:

```sh id="portable-coverage-diff"
greenlight coverage:diff \
    --baseline=baseline.json \
    --current=current.json \
    --baseline-root=/old/checkout \
    --current-root=/new/checkout
```

The command removes the applicable root and `/` from each file key. It uses the
resulting project-relative path only for that comparison. Each file key
**MUST** be below its applicable root. The command rejects a document that has
a key outside the root.

Use `--baseline-root` and `--current-root` together. Greenlight resolves a
relative root from the command working directory. An absolute root can refer
to a checkout that is not on the current machine.

The options do not change the JSON documents. Version 1 keeps its absolute-path
contract. A JSON format that stores project-relative keys requires a new schema
version.

The difference command can also apply `--minimum-coverage` and
`--maximum-uncovered-lines` to the current document. These gates do not add
fields to the document.

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

### files.*.functions

Version 2 adds a sorted list of function scopes. Each function has a non-empty
`name`, a `branches` list, and a `paths` list.

A branch has these fields:

* `id`: the branch position in its function after Greenlight sorts the Xdebug
  branches by opcode start
* `startLine` and `endLine`: its source range
* `covered`: whether the branch ran
* `exits`: ordered exit IDs and hit states

A path has an ordered, non-empty `branches` list and a `covered` hit state. The
function name plus the exact sequence of normalized branch IDs identifies a
path.

Greenlight sorts functions by name, branches by ID, exits by ID, and paths by
their branch sequence. Raw opcode offsets can differ when processes compile a
file at different points in startup. Greenlight normalizes these offsets before
it merges coverage. It does not derive branch or path hits from line hits.

Version 2 also adds derived `coveredBranches`, `branches`,
`branchPercentage`, `coveredPaths`, `paths`, and `pathPercentage` fields to
each file. Importers calculate these values from `functions` and ignore the
stored derived values.

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

### Version 2 totals

Version 2 adds `coveredBranches`, `branches`, and `branchPercentage`. A branch
is one Xdebug branch node, not one source line. It also adds `coveredPaths`,
`paths`, and `pathPercentage`.

Greenlight rounds the percentages to two decimal places. A result with no
branches or paths reports `100.0` for the applicable percentage.

## Merge behavior

The coverage merge command reads two or more documents of one version:

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

For version 2, the merge uses function name and normalized branch identity. A
branch or path has coverage when any input records a hit. The command rejects
conflicting source metadata for one identity. It also rejects a mix of version
1 and version 2 because that mix cannot show whether all shards measured
branches.

A file can be absent from an input. The result contains that file if a different
input contains it.

The command rejects malformed documents and unsupported versions. Without root
options, each file path must be an absolute version 1 path.

The command accepts aggregate coverage JSON versions 1 and 2. Per-test coverage
JSONL is a separate format and is not a merge input.

For different roots, give one `--input-root` for each input. Give one
`--project-root` for the output. Greenlight removes each applicable input root
and adds the output root.

The command rejects these root errors:

* The number of input roots differs from the number of inputs.
* A path is outside its applicable input root.
* The same input has different input roots.

Root relocation does not change the schema. The JSON output remains version 1
and contains absolute file paths.

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

Versions `1` and `2` **MAY** receive additive fields.

Readers **MUST** ignore unknown keys.

If version 1 defines a field, a change to its definition or shape **MUST** use a
new `v` value.
