# Orchestrator-owned integration fixtures

Status: accepted

## Decision

Greenlight models external test infrastructure as an orchestrator-owned
integration fixture graph.

Plugins declare fixtures through `IntegrationFixtureProvider`. Each
`IntegrationFixtureDefinition` has a globally unique ID, a provisioning closure,
and explicit dependencies. The orchestrator validates and topologically orders
the graph, provisions it after discovery and sharding, and owns a reverse-order
cleanup stack.

A fixture exposes a shared `FixtureResource` and optional overlays keyed by
Greenlight channel. The orchestrator merges the shared data with one overlay and
sends only that result to the matching worker. Data crosses the existing
authenticated local protocol as JSON-safe values. Secrets occupy a separate
map, are redacted from object debug views, and require an explicit `reveal()`
call.

Workers acknowledge a new bootstrap phase before they can receive assignments.
During bootstrap they load their configured plugin instances, invoke
`WorkerBootstrapSubscriber`, and build the harness registry. The initial pool
uses an all-ready barrier; replacements need only complete their own bootstrap.

Fixtures live for one selected run iteration. Their resources survive retries,
worker recycling, isolated workers, and worker crashes because workers do not
own them. Repeat iterations, watch reruns, and independent shards each provision
a new graph.

Cleanup runs after `RunFinished` on success and from the same runner failure
boundary for provisioning failures, worker startup failures, test failures,
protocol failures, reporter failures, and graceful signal shutdown. Every
registered callback is attempted. Cleanup failures are reported without hiding
the original failure.

## Context

`GREENLIGHT_CHANNEL` and `TestChannel` already provide stable concurrency slots,
but applications have had to create resources outside Greenlight or lazily
inside workers. Worker ownership cannot reliably clean up after a crash and can
duplicate expensive infrastructure during recycling. Environment variables are
also a poor transport for structured values and credentials.

The existing `HarnessProvider` and `ServiceResolver` APIs solve worker-local
object construction. They cannot own a resource whose lifetime must span
multiple physical workers. `RunLifecycleSubscriber` runs in the right process
but is an observation API and has no dependency graph, resource transport, or
cleanup contract.

## Consequences

The public terminology distinguishes the two lifetimes:

* an **integration fixture** is orchestrator-owned external infrastructure
* a **fixture resource** is serializable connection or addressing data
* an **integration resource catalog** is one worker channel's view
* a **harness service** is a worker-local injectable object

Plugins can combine capabilities. An integration fixture provider creates the
external resource, a worker bootstrap subscriber reads the channel catalog, and
a harness provider adapts it to application-specific injectable services.

The wire protocol advances to version 2 and adds `bootstrap` and `ready`.
Configuration moves from the first `assign` frame into `bootstrap`. This is an
internal compatibility break only; public plugin and configuration APIs remain
additive.

The initial barrier trades some startup latency for deterministic safety: no
test can mutate shared infrastructure while another initial worker is still
running its bootstrap hook. Replacements do not stop already-running workers.

Each channel catalog is limited to 1 MiB. This keeps bootstrap bounded and
encourages plugins to send addresses and short-lived credentials instead of
large datasets.

## Rejected alternatives

**Provision inside each worker.** This couples infrastructure lifetime to a
crash-prone process, repeats work during recycling, and cannot coordinate shared
resources.

**Use run lifecycle events for setup and teardown.** Event subscribers are
observers. Adding mutable setup state and teardown conventions there would make
event delivery order an implicit resource API and still would not transport
typed channel data.

**Put resource values in environment variables.** Environment variables flatten
types, are inherited by child processes, are commonly captured in diagnostics,
and expose every channel's values unless the orchestrator constructs a separate
environment for each one.

**Send arbitrary PHP objects.** Serialization would couple orchestrator and
worker memory models, expand the attack surface, and make compatibility and
redaction difficult. Workers construct live clients through harness services
instead.

**Share one fixture graph across CI shards.** Shards are deliberately
coordination-free and may run on unrelated hosts. Plugins that want shared
remote infrastructure must coordinate it externally and make cleanup
idempotent.

## Limits and future decisions

Greenlight cannot execute process-local teardown after SIGKILL, a second signal,
machine loss, or forcible orchestrator termination. External resources that
must survive those conditions need leases, TTLs, or an out-of-process reaper.

The first API has run-wide and per-channel resources only. Per-suite graphs,
on-demand fixture activation, cross-shard leases, secret-store references, and
bounded parallel provisioning remain possible extensions, but are not implied
by this decision.
