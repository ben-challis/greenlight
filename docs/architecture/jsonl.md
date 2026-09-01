# JSONL reporter schema

The `jsonl` reporter is Greenlight's machine-readable run output.

It writes one JSON object per line as each event occurs. Typical consumers
include IDEs, dashboards, and tools for intermittent test failures.

A machine-readable JSON Schema for version 1 is at
[resources/schema/jsonl-v1.schema.json](../../resources/schema/jsonl-v1.schema.json).
Tests verify that each reporter line conforms to this schema. The schema
defines the required keys and their types.

## Envelope

Each line is one JSON object with three keys:

```json id="zk90n2"
{"v": 1, "event": "test-finished", "data": {"result": {"...": "..."}, "occurredAt": 1750000000.5}}
```

### v

The current version is `1`.

### event

A stable short tag for the event type.

### data

The event payload.

The event `toWire()` method produces this payload.

One internal event codec owns the tag map, envelope validation, payload
decoding, and event construction. The JSONL reporter and `profile:report` use
the same codec as the worker protocol.

Each line ends with `\n`.

A stream can contain event sequences for multiple complete runs. Repeat modes
write one sequence for each iteration. Repeat status goes to standard error
when the command uses the `jsonl` reporter.

Output uses UTF-8 JSON. Greenlight cleans captured strings that contain invalid
UTF-8 before they reach the reporter. The encoder replaces invalid sequences
that remain.

## Versions

The `v` field identifies the schema version.

Consumers **SHOULD** validate against the schema named by `v`. They **SHOULD**
treat an unsupported version as data that they cannot parse.

Greenlight **MAY** add optional payload keys within a version. Consumers
**MUST** ignore unknown keys. New required keys, event tags, or enum values
require a new version. When a type or its definition changes, use a new version.

[Compatibility](compatibility.md) defines the complete policy, order
guarantees, and incomplete-output behavior.

## Event tags

| Tag              | Event                 | Payload keys                                                           |
| ---------------- | --------------------- | ---------------------------------------------------------------------- |
| `run-started`    | Run begins            | `runId`, `plannedTests`, `workers`, `occurredAt`, `artifactsDirectory` |
| `run-finished`   | Run ends              | `runId`, `summary`, `durationSeconds`, `occurredAt`, `workerTimings`   |
| `class-started`  | Test class begins     | `class`, `occurredAt`, `workerId`, `isolated`                          |
| `class-finished` | Test class ends       | `class`, `occurredAt`, `workerId`                                      |
| `test-started`   | Test begins           | `id`, `occurredAt`                                                     |
| `test-finished`  | Test ends             | `result`, `occurredAt`                                                 |
| `worker-spawned` | Worker process starts | `workerId`, `pid`, `occurredAt`                                        |

`run-started.workers` is the worker-count limit for the selected execution
method. It does not report the number of processes that Greenlight starts.

A process-pool run can start fewer processes when the plan or resource limits
need fewer workers. An in-process run reports `1`.

Use `worker-spawned` events and `run-finished.workerTimings` to identify the
processes that Greenlight started.

`run-finished.summary` contains the passed, failed, errored, and skipped totals.

`run-finished.workerTimings` is an optional list. Each item contains timing
data for one worker process. Greenlight omits the key when timing data is not
available.

Each item contains these durations in seconds:

* `spawnToHelloSeconds`
* `helloToReadySeconds`
* `readyToFirstAssignmentSeconds`
* `assignmentGapSeconds`
* `bootstrapBarrierSeconds`
* `resourceCapacitySeconds`
* `noQueuedWorkSeconds`
* `retirementToExitSeconds`

The item also contains `workerId` and `assignmentGaps`. A nullable duration is
`null` when the worker did not reach both phase endpoints.

The orchestrator calculates these values from protocol and scheduler state.
Thus, worker test loops do not contain profile instrumentation. The idle values
contain only states that the orchestrator can distinguish.

`run-started.artifactsDirectory` is the absolute target directory for retained
evidence from this run. Its value is `null` when an artifact directory is not
available. The directory can be absent if the run retains no attachments.

`test-started.id` is the test ID. It contains the class, method, and optional
data-set key.

`class-started.workerId` and `class-finished.workerId` name the worker that
ran the class.

`class-started.isolated` is true when the worker runs an isolated test.

`occurredAt` is a Unix timestamp with microsecond precision. Consumers
**SHOULD** accept a JSON number with decimals or an integer. Some JSON round
trips can convert a whole-number float to an integer.

Events from different workers can interleave. Arrival order helps a live
display, but it is not a deterministic test order.

## The test-finished payload

`data.result` contains the complete test result.

### id

The test ID:

```json id="ifx1kl"
{
    "class": "App\\Tests\\GreetingTest",
    "method": "greetsByName",
    "dataSetKey": null
}
```

`dataSetKey` is `null` when the test has no data set.

### outcome

One of these values:

* `passed`
* `failed`
* `errored`
* `skipped`

Retries do not add a separate outcome. After a retry, the test still ends with
one of these four values.

### durationSeconds

The test duration in seconds.

### memoryDeltaBytes

The memory delta for the test, in bytes.

### attempts

The number of attempts used.

This value is `1` unless the test used more than one attempt.

For a `passed` outcome, a value greater than `1` identifies a retried pass.
This combination is evidence of instability.

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

Greenlight renders `expected` and `actual` before it sends them to the reporter.
Each field is a string or `null`.

`location` is an object with `file` and `line`, or `null`.

### error

The value describes a test, condition, plugin, framework, or synthetic
worker-crash error. Its value is an object or `null`.

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

These records identify each plugin that changed the outcome.

### output

The output record that Greenlight captured during a test attempt. An active
capture window produces a record, even when standard output is empty.

The value is `null` when the result has no capture record. A test can disable
capture. Greenlight can also create a result outside a test attempt.

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

The two output flags show that output capture reached its size limit.

### risky

The value shows whether Greenlight identified the test as risky.

### expectations

The number of expectations that Greenlight verified during the final attempt.

Each matcher in a chain counts one time. Each mock expectation counts when
Greenlight verifies it. Stubs do not count.

An `eventually()` or `consistently()` matcher counts once. Calls to its probe do
not count separately.

Failed, errored, and skipped tests contain the partial count from before the
test stopped.

### attachments

A list of metadata for retained attachments.

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
`always`. Greenlight stores content separately at `path`. It does not embed
content or its base64 form in JSONL.

`path` is a published path, not the caller source path. Internal storage keys
never appear in reporter output.

Attachments from failed retry attempts remain on the final result. They keep
their original `attempt` number. A successful test can have attachments with
the `always` retention value.

Source locations and published artifact paths are absolute. They can disclose
the layout of the workspace that produced them. They are not portable
identifiers. See [compatibility](compatibility.md#paths-and-portability).
