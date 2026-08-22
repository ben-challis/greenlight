# Orchestrator-owned integration fixtures

Greenlight provisions external test infrastructure in the orchestrator. It
sends each worker only the connection data for that worker's channel and tears
the infrastructure down when the run ends.

## Ownership and lifetime

Plugins declare integration fixtures through `IntegrationFixtureProvider`.
The run coordinator provisions them after discovery, selection, and sharding.
Provisioning finishes before `RunStarted` and before Greenlight starts an
execution adapter.

The provider instance belongs to the orchestrator. If the same plugin class has
worker capabilities, those capabilities use a separate instance in each
worker. Plugin properties do not cross this seam. The provider MUST use
`IntegrationFixtureContext::expose()` to transfer supported fixture data.

Greenlight provisions one fixture graph per selected run. Each repeat iteration
and watch rerun gets a fresh graph. CI shards provision independently because
Greenlight does not coordinate them across machines.

Replacing a worker does not provision the graph again. Retries, isolated
tests, and replacement workers reuse the resources assigned to their channel.

## Provisioning

An `IntegrationFixtureDefinition` names the fixture, provides its provisioning
closure, and lists any dependencies. Greenlight validates the whole graph
before it provisions anything, then provisions dependencies first.

Fixture IDs are non-empty UTF-8 strings. They cannot use integer strings
because PHP converts those map keys to integers.

Call `IntegrationFixtureContext::defer()` as soon as the provisioner acquires a
resource. The callback will then run even if the rest of the provisioner fails.
Greenlight runs cleanup callbacks in reverse registration order.

`IntegrationFixtureContext::expose()` publishes a shared `FixtureResource` and
optional channel overlays. Greenlight merges the shared values with the current
channel's overlay before it sends them to a worker.

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
message that contains the channel, config path, and resources. The worker loads
its plugin definitions, creates its worker-side instances, calls
`WorkerBootstrapSubscriber`, builds the harness registry, and then replies with
`ready`.

When a configured plugin implements `WorkerBootstrapSubscriber`, the
orchestrator waits for every initial worker to report `ready` before it assigns
a test. This barrier preserves the run-wide bootstrap lifecycle guarantee. A
replacement worker waits only for its own bootstrap because the rest of the
pool can still run tests.

Without this subscriber, each initial worker can receive its first assignment
after its own bootstrap. Integration fixture provisioning still finishes before
Greenlight starts any worker.

Tests can inject `IntegrationResources` directly. A plugin can instead read the
resources in `WorkerBootstrapSubscriber` and expose an application-specific
client through `HarnessProvider` or `ServiceResolver`.

## Teardown

The run coordinator starts teardown after `RunFinished` on a successful run.
Provisioning, bootstrap, worker, protocol, reporter, and test failures also
trigger teardown. Greenlight runs every registered callback even if one throws.
A teardown failure fails the run but does not replace an earlier failure.

When the orchestrator receives its first SIGINT or SIGTERM, it drains workers
before teardown. SIGKILL, a second signal, process loss, and machine loss cannot
run process-local callbacks. Protect resources from those cases with leases,
expiry times, or an external reaper.
