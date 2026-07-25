# Orchestrator-owned integration fixtures

This document describes how Greenlight provisions external test infrastructure,
passes connection data to workers, and tears the infrastructure down.

## Ownership and lifetime

Integration fixtures belong to the orchestrator. A plugin declares them through
`IntegrationFixtureProvider`, and Greenlight provisions them after discovery,
selection, and sharding. Provisioning finishes before `RunStarted` and before
workers are spawned.

One fixture graph belongs to one selected run. Each repeat iteration and watch
rerun gets a fresh graph. CI shards provision independently because Greenlight
does not coordinate shards across machines.

The graph outlives individual worker processes. Retries, recycled workers,
isolated tests, and crash replacements continue to use the same fixture
resources for their channel.

## Provisioning

Each `IntegrationFixtureDefinition` has an ID, a provisioning closure, and an
optional list of dependencies. Greenlight validates the whole graph before
provisioning and runs dependencies first.

Provisioners register cleanup with `IntegrationFixtureContext::defer()`. A
callback should be registered as soon as its resource is acquired, so it still
runs if the rest of the provisioner fails. Callbacks run in reverse registration
order.

A provisioner publishes a shared `FixtureResource` and may add one overlay per
channel. Shared values and the current channel overlay are merged before the
worker receives them.

## Resource transport

Fixture resources support JSON-safe nulls, booleans, finite numbers, UTF-8
strings, lists, and maps. A complete channel resource payload is limited to
1 MiB.

Secrets use a separate map. Worker code receives them as `SensitiveValue`
objects and must call `reveal()` to read the string. Object dumps and exports
redact the value.

Resources travel in the authenticated local worker protocol. Greenlight does
not put them in environment variables, command arguments, stdout, or stderr.
Each worker receives only its own channel overlay.

## Worker bootstrap

Protocol version 2 adds `bootstrap` and `ready` messages. After `hello`, the
orchestrator sends the worker its channel, config path, and resources. The
worker then loads plugins, calls `WorkerBootstrapSubscriber`, builds the harness
registry, and replies with `ready`.

The initial workers must all report ready before any test starts. A replacement
worker only waits for its own bootstrap because the rest of the pool may still
be running tests.

Tests can inject `IntegrationResources` directly. A plugin can instead read the
resources in `WorkerBootstrapSubscriber` and expose an application-specific
client through `HarnessProvider` or `ServiceResolver`.

## Teardown

On a successful run, teardown starts after `RunFinished`. The same cleanup path
runs after provisioning, bootstrap, worker, protocol, reporter, or test
failures. Greenlight attempts every registered callback even if one throws.
Cleanup failures fail the run without replacing an earlier failure.

The first SIGINT or SIGTERM drains workers before teardown. SIGKILL, a second
signal, process loss, and machine loss cannot run process-local callbacks.
Resources that need protection from those cases should also use leases, expiry
times, or an external reaper.
