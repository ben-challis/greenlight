# Orchestrator-owned integration fixtures

Greenlight provisions external test infrastructure in the orchestrator. It
sends each worker only the connection data for that worker's channel and tears
the infrastructure down when the run ends.

## Ownership and lifetime

Plugins declare integration fixtures through `IntegrationFixtureProvider`.
Greenlight provisions them in the orchestrator after discovery, selection, and
sharding. Provisioning finishes before `RunStarted` and before Greenlight spawns
workers.

Greenlight provisions one fixture graph per selected run. Each repeat iteration
and watch rerun gets a fresh graph. CI shards provision independently because
Greenlight does not coordinate them across machines.

Recycling or replacing a worker does not re-provision the graph. Retries,
isolated tests, and replacement workers reuse the resources assigned to their
channel.

## Provisioning

An `IntegrationFixtureDefinition` names the fixture, provides its provisioning
closure, and lists any dependencies. Greenlight validates the whole graph
before it provisions anything, then provisions dependencies first.

Call `IntegrationFixtureContext::defer()` as soon as the provisioner acquires a
resource. The callback will then run even if the rest of the provisioner fails.
Greenlight runs cleanup callbacks in reverse registration order.

`IntegrationFixtureContext::expose()` publishes a shared `FixtureResource` and
optional channel overlays. Greenlight merges the shared values with the current
channel's overlay before sending them to a worker.

## Resource transport

`FixtureResource` accepts JSON-safe nulls, booleans, finite numbers, UTF-8
strings, lists, and maps. Greenlight rejects a complete channel payload larger
than 1 MiB.

Store credentials in the separate secrets map. Worker code receives each secret
as a `SensitiveValue` and must call `reveal()` to read it. Object dumps and
exports redact the value.

Greenlight sends resources through the authenticated local worker protocol. It
does not put them in environment variables, command arguments, stdout, or
stderr. A worker receives only its own channel overlay.

## Worker bootstrap

After a worker sends `hello`, the orchestrator replies with a `bootstrap`
message containing the channel, config path, and resources. The worker loads
its plugins, calls `WorkerBootstrapSubscriber`, builds the harness registry, and
then replies with `ready`.

The orchestrator waits for every initial worker to report `ready` before it
assigns a test. A replacement worker waits only for its own bootstrap because
the rest of the pool may still be running tests.

Tests may inject `IntegrationResources` directly. A plugin may instead read the
resources in `WorkerBootstrapSubscriber` and expose an application-specific
client through `HarnessProvider` or `ServiceResolver`.

## Teardown

Greenlight starts teardown after `RunFinished` on a successful run.
Provisioning, bootstrap, worker, protocol, reporter, and test failures also
trigger teardown. Greenlight runs every registered callback even if one throws.
A teardown failure fails the run but does not replace an earlier failure.

When the orchestrator receives its first SIGINT or SIGTERM, it drains workers
before teardown. SIGKILL, a second signal, process loss, and machine loss cannot
run process-local callbacks. Protect resources from those cases with leases,
expiry times, or an external reaper.
