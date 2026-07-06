# RFC-003: wire protocol and process pool

| | |
|---|---|
| **Status** | accepted |
| **Phase** | 5b (docs/plan/phase-05b-orchestrator.md) |
| **Author** | Ben Challis |
| **Date** | 2026-07-07 |

## Context

The orchestrator and its worker processes need a transport, a framing, a message vocabulary, and lifecycle rules (spawn, recycle, crash). This is the named highest technical risk of the project; the wire model is deliberately minimal. Motivated by PRD section 7 and RFC-001's serialisation contract.

## Decision

### Transport

The orchestrator listens on a stream socket and hands each worker the address as an argument. On POSIX the socket is `unix://` under a per-run directory in the system temp dir (unix socket paths are limited to roughly a hundred bytes, which project-relative paths exceed); where unix sockets are unavailable the fallback is `tcp://127.0.0.1:<ephemeral>`. Workers are spawned with `proc_open` (no `pcntl` requirement), running the hidden internal command `bin/greenlight __worker <address> <workerId>`; that command is undocumented, `@internal`, and carries no compatibility promise.

Worker stdin is unused. Worker stdout and stderr are piped to the orchestrator and surfaced as diagnostics, never parsed as protocol; test output escaping through them cannot corrupt frames because frames never travel over stdio.

A per-run token is included in the first message from each worker; connections that do not authenticate within a short deadline are dropped. This keeps a local tcp fallback listener from accepting stray connections.

### Framing

A frame is a 4-byte big-endian unsigned length followed by that many bytes of JSON. JSON is encoded with `JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE` (defence in depth behind the capture-side scrubbing from RFC-001). Frames above a limit (default 8 MiB) are a protocol error. The codec sits behind a `FrameCodec` interface; JSON is the v1 encoding and a binary encoding may replace it later if measurement justifies it.

### Envelope and message vocabulary

Every frame is an envelope: `{"v": 1, "t": "<type>", "p": {...}}`. Unknown `v` or `t` is a protocol error. Types are stable short tags mapped to classes by a registry in `Greenlight\Runner\Protocol`; class names never appear on the wire.

Orchestrator to worker:

- `assign`: a plan slice (wire-serialised plan entries) plus run settings the worker needs (registry configuration arrives with the plugin phase).
- `drain`: finish the current test, send `done`, exit. Used by `--bail` and shutdown.

Worker to orchestrator:

- `hello`: workerId, token, pid.
- `event`: one RFC-001 event (test class and test level; workers never emit run-level events).
- `recycling`: the worker has hit its recycle threshold (test count or memory), will finish nothing further from its slice, and reports the remaining entry ids.
- `done`: slice complete, with the worker's summary and peak memory.
- `fatal`: last-gasp report before an orderly abnormal exit (an unhandled framework error, not a test failure).

### Distribution and recycling

Test classes are assigned to workers by stable hash of the class name modulo the worker count, so a given seed and code state always yield the same placement. A timing cache (`.greenlight/timings`, written after each run) optionally rebalances whole classes from the slowest worker to the fastest at plan time; rebalancing is deterministic given the cache contents.

Recycling is worker-initiated (the worker knows its own memory) and orchestrator-confirmed: on `recycling`, the orchestrator emits `WorkerRecycled`, spawns a replacement, and assigns it the reported remainder. `#[Isolated]` tests get a dedicated fresh worker that is recycled immediately after.

### Crash containment

A worker that disappears without `done` (socket EOF, process exit) is a crash. The orchestrator attributes an errored result to the in-flight test (the last `TestStarted` without a matching `TestFinished`), emits `WorkerRecycled` with the crash reason, respawns, and reassigns the remainder excluding the crashed test. Crashed tests are never automatically retried; a crash loop must surface, not burn CI minutes.

An orchestrator-side watchdog enforces `#[Timeout]` hard kills: budget plus a grace factor, then the worker is killed and crash containment applies with a timeout-specific message.

### Exit conditions

The run ends when every slice is `done` or drained. The orchestrator emits `RunStarted`/`RunFinished` and owns the summary; worker summaries are cross-checked against the event stream and a mismatch is a protocol error (fail loudly, never report green on a bookkeeping bug).

## Consequences

Frozen: the envelope shape, the framing, the message vocabulary above (additive growth allowed), worker-initiated recycling, and the crash attribution rule. Not frozen: the internal `__worker` invocation, the timing cache format, and the codec encoding behind `FrameCodec`.

## Alternatives considered

- Frames over worker stdio: rejected; test code writes to stdout, and pre-capture output would corrupt frames. Sockets keep protocol and diagnostics on separate channels.
- `pcntl_fork` instead of `proc_open`: rejected; forking inherits the orchestrator's full heap (copy-on-write is defeated by PHP refcounting), requires `pcntl`, and does not exist on Windows.
- Shipping data-set argument values in `assign`: rejected in RFC-002 already; workers re-expand providers.
- msgpack or a custom binary codec for v1: rejected until measured; JSON frames are debuggable with a pipe and `xxd`, and the codec seam makes the swap cheap.
