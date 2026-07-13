# JSONL reporter schema

The `jsonl` reporter is Greenlight's machine-readable run output.

It writes one JSON object per line, streamed as events occur. Typical consumers
include IDEs, dashboards, and flaky-test tooling.

A machine-readable JSON Schema for version 1 ships at
[resources/schema/jsonl-v1.schema.json](../../resources/schema/jsonl-v1.schema.json).
Every line the reporter emits validates against it, enforced by tests. The
schema states the floor of the contract: required keys and their types.
Payloads may carry additional keys, per the versioning policy below.

## Envelope

Each line is one JSON object with three keys:

```json id="zk90n2"
{"v": 1, "event": "test-finished", "data": {"result": {"...": "..."}, "occurredAt": 1750000000.5}}
```

### v

Schema version.

The current version is `1`.

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

Version `1` changes additively only:

* New event tags may be added.
* New keys may be added to `data` payloads.
* Existing event tags do not change meaning.
* Existing `data` keys are not removed or retyped.

Consumers should skip events whose `event` tag they do not recognize.

Consumers should ignore unknown keys inside `data`.

A non-additive change requires a new `v` value. Consumers should treat an
unknown version as unparseable.

## Event tags

| Tag               | Event                      | Payload keys                                        |
| ----------------- | -------------------------- | --------------------------------------------------- |
| `run-started`     | Run begins                 | `runId`, `plannedTests`, `workers`, `occurredAt`    |
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

`suite-started` and `suite-finished` are reserved event types.
Greenlight does not emit them today because suites only group configuration paths into a single discovery set,
so execution has no suite boundary.

They are defined in the event list and schema now to preserve their meaning if suite-scoped execution is added later.
Consumers must not wait for these events and, under the versioning policy, must tolerate them appearing in a future
release.

`test-started.id` is the test id: class, method, and data-set key when present.

`class-started.workerId` and `class-finished.workerId` name the worker that
ran the class. Older streams may omit the key.

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

Each matcher in a chain counts once. Soft-mode failures count. Each mock
expectation counts when it is verified. Stubs do not count.

Failed, errored, and skipped tests carry the partial count verified before the
test stopped.

Older streams may omit this key. Consumers should treat a missing
`expectations` key as `0`.
