# JSONL reporter schema

The `jsonl` reporter is Greenlight's machine-readable run output.

It writes one JSON object per line, streamed as events occur. Typical consumers
include IDEs, dashboards, and flaky-test tooling.

A machine-readable JSON Schema for version 2 ships at
[resources/schema/jsonl-v2.schema.json](../../resources/schema/jsonl-v2.schema.json).
Every line the reporter emits validates against it, enforced by tests. The
schema defines the required keys and their types.

## Envelope

Each line is one JSON object with three keys:

```json id="zk90n2"
{"v": 2, "event": "test-finished", "data": {"result": {"...": "..."}, "occurredAt": 1750000000.5}}
```

### v

Schema version.

The current version is `2`.

### event

A stable short tag for the event type.

### data

The event payload.

This is the payload produced by the event's `toWire()` method.

Lines are terminated with `\n`.

Output is UTF-8 JSON. Strings captured from invalid UTF-8 are scrubbed before
they reach the reporter, and the encoder substitutes any remaining invalid
sequences.

## Versioning

The schema is versioned by the `v` field.

Each version has its own schema. Consumers should validate against the schema
named by `v` and treat an unsupported version as unparseable.

## Event tags

| Tag               | Event                      | Payload keys                                        |
| ----------------- | -------------------------- | --------------------------------------------------- |
| `run-started`     | Run begins                 | `runId`, `plannedTests`, `workers`, `occurredAt`, `artifactsDirectory` |
| `run-finished`    | Run ends                   | `runId`, `summary`, `durationSeconds`, `occurredAt` |
| `suite-started`   | Suite begins               | `suite`, `occurredAt`                               |
| `suite-finished`  | Suite ends                 | `suite`, `occurredAt`                               |
| `class-started`   | Test class begins          | `class`, `occurredAt`, `workerId`                   |
| `class-finished`  | Test class ends            | `class`, `occurredAt`, `workerId`                   |
| `test-started`    | Test begins                | `id`, `occurredAt`                                  |
| `test-finished`   | Test ends                  | `result`, `occurredAt`                              |
| `worker-spawned`  | Worker process starts      | `workerId`, `pid`, `occurredAt`                     |
| `worker-recycled` | Worker process is replaced | `workerId`, `reason`, `occurredAt`                  |

`run-finished.summary` contains passed, failed, errored, and skipped counts.

`run-started.artifactsDirectory` is the absolute target directory for retained
evidence from this run, or `null` when no artifact directory is available. The
directory may not exist if the run retains no attachments.

`suite-started` and `suite-finished` are reserved event types.
Greenlight does not emit them today because suites only group configuration paths into a single discovery set,
so execution has no suite boundary.

They are defined in the event list and schema now to preserve their meaning if suite-scoped execution is added later.
Consumers must not wait for these events.

`test-started.id` is the test id: class, method, and data-set key when present.

`class-started.workerId` and `class-finished.workerId` name the worker that
ran the class.

`worker-recycled.reason` is one of:

* `test-count`
* `memory`
* `crash`

`occurredAt` is a Unix timestamp with microsecond precision. Consumers should
accept either a JSON number with decimals or an integer, since some JSON round
trips may narrow whole-number floats.

## The test-finished payload

`data.result` contains the full test result.

### id

The test id:

```json id="ifx1kl"
{
    "class": "App\\Tests\\GreetingTest",
    "method": "greetsByName",
    "dataSetKey": null
}
```

`dataSetKey` is `null` unless the test came from a data set.

### outcome

One of:

* `passed`
* `failed`
* `errored`
* `skipped`

Retries do not add a separate outcome. A retried test still ends with one of
these four values.

### durationSeconds

The test duration in seconds.

### memoryDeltaBytes

The memory delta for the test, in bytes.

### attempts

The number of attempts used.

This is `1` unless the test was retried.

### failures

A list of expectation failures.

Each item has this shape:

```json id="p2eqoc"
{
    "message": "Expected values to be equal.",
    "expected": "...",
    "actual": "...",
    "location": {
        "file": "/project/tests/GreetingTest.php",
        "line": 17
    }
}
```

`expected` and `actual` are pre-rendered strings or `null`.

`location` is an object with `file` and `line`, or `null`.

### error

The thrown error or exception, or `null`.

When present, it has this shape:

```json id="sp1qrb"
{
    "class": "RuntimeException",
    "message": "Something failed.",
    "file": "/project/tests/GreetingTest.php",
    "line": 17,
    "stackFrames": []
}
```

### skipReason

The skip reason, or `null`.

### transformations

A list of outcome transformation records.

Each item has this shape:

```json id="u7c63g"
{
    "transformedBy": "PluginName",
    "from": "failed",
    "to": "skipped"
}
```

These records provide provenance for plugin outcome changes.

### output

The output captured during the test, or `null` when nothing was captured.

When present, it has this shape:

```json id="w4mt8a"
{
    "stdout": "...",
    "diagnostics": [
        {
            "severity": "warning",
            "message": "Undefined array key 0",
            "file": "/project/tests/GreetingTest.php",
            "line": 21
        }
    ],
    "stdoutTruncated": false,
    "diagnosticsTruncated": false
}
```

`severity` is one of `notice`, `warning`, or `deprecation`.

The truncation flags record that capture hit its size limit.

### risky

Whether the test was flagged as risky.

### expectations

The number of expectations verified during the final attempt.

Each matcher in a chain counts once. Each mock expectation counts when it is
verified. Stubs do not count.

An `eventually()` or `consistently()` matcher counts once. Calls to its probe do
not count separately.

Failed, errored, and skipped tests carry the partial count verified before the
test stopped.

### attachments

A list of retained attachment metadata.

```json
{
    "name": "response.json",
    "kind": "value",
    "mediaType": "application/json",
    "sizeBytes": 147,
    "sha256": "0000000000000000000000000000000000000000000000000000000000000000",
    "attempt": 1,
    "path": "/project/build/greenlight-artifacts/run-id/Test-response.json",
    "retention": "on-failure"
}
```

`kind` is `value`, `text`, `binary`, or `file`. `retention` is `on-failure` or
`always`. Content is stored out of band at `path`; it is never embedded or
base64-encoded in JSONL. `path` is a published path, not the caller's source
path. Internal storage keys never appear in reporter output.

Attachments from failed retry attempts remain on the final result with their
original `attempt` number. A passing test can have attachments when their
retention is `always`.
