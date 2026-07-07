# JSONL reporter schema

The `jsonl` reporter is the machine interface to a Greenlight run: one JSON object per line, one line per event, streamed as events arrive. Consumers are IDEs, dashboards, and flaky-test tooling.

## Envelope

Every line is a single JSON object with exactly three keys:

```json
{"v": 1, "event": "test-finished", "data": {"result": {"...": "..."}, "occurredAt": 1750000000.5}}
```

- `v` is the schema version, currently `1`.
- `event` is a stable short tag naming the event type.
- `data` is the event's wire payload, exactly as produced by the event's `toWire()` method.

Lines are terminated by `\n`. Output is UTF-8; strings that originated as invalid UTF-8 have already been scrubbed at capture, and the encoder substitutes any remaining invalid sequences.

## Versioning

The schema is versioned by the `v` field and changes additively only:

- New event tags may appear. Consumers must skip lines whose `event` tag they do not recognise.
- New keys may appear inside `data` payloads. Consumers must ignore keys they do not recognise.
- Existing tags never change meaning, and existing `data` keys are never removed or retyped.

A change that cannot be made additively increments `v`. Consumers should treat an unknown `v` as unparseable rather than guessing.

## Event tags

| Tag | Event | Payload keys |
|---|---|---|
| `run-started` | run begins | `runId`, `plannedTests`, `workers`, `occurredAt` |
| `run-finished` | run ends | `runId`, `summary` (passed/failed/errored/skipped counts), `durationSeconds`, `occurredAt` |
| `suite-started` | suite begins | `suite`, `occurredAt` |
| `suite-finished` | suite ends | `suite`, `occurredAt` |
| `class-started` | test class begins | `class`, `occurredAt` |
| `class-finished` | test class ends | `class`, `occurredAt` |
| `test-started` | test begins | `id` (class/method/dataSetKey), `occurredAt` |
| `test-finished` | test ends | `result` (full test result), `occurredAt` |
| `worker-spawned` | worker process starts | `workerId`, `pid`, `occurredAt` |
| `worker-recycled` | worker process replaced | `workerId`, `reason` (`test-count`, `memory`, `crash`), `occurredAt` |

`occurredAt` is a Unix timestamp with microsecond precision. JSON round trips may narrow floats to ints; consumers should accept both.

## The test-finished payload

`data.result` carries the full result model:

- `id`: `{"class": ..., "method": ..., "dataSetKey": ...}` where `dataSetKey` is null unless the test came from a data set.
- `outcome`: one of `passed`, `failed`, `errored`, `skipped`. Retries do not add an outcome; a retried test still terminates in one of these four.
- `durationSeconds`, `memoryDeltaBytes`, `attempts` (1 unless retried).
- `failures`: a list of `{"message", "expected", "actual", "location"}` objects; `expected` and `actual` are pre-rendered strings or null, `location` is `{"file", "line"}` or null.
- `error`: `{"class", "message", "file", "line", "stackFrames"}` or null.
- `skipReason`: string or null.
- `transformations`: a list of `{"transformedBy", "from", "to"}` provenance records for plugin outcome changes.
- `expectations`: the number of expectations verified during the final attempt. Each matcher in a chain counts once, soft-mode failures count, and each mock expectation counts at verification; stubs never count. Failed, errored, and skipped tests carry the partial count verified before the abort. Streams written before this field existed omit the key; consumers should treat a missing key as 0.
