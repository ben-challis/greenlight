# Machine resource coordination

Greenlight uses this design to coordinate scarce resources between processes on
one machine. [Configuration](../configuration.md#machine-scoped-resource-limits)
documents the public interface. This page documents the implementation
constraints.

## Decision

Machine-scoped resource limits use advisory file locks. They add file locks to
the class scheduler. They do not require a daemon, database, or distributed
lock service.

The processes must use the same OS user, coordination namespace, and system
temporary directory. The mechanism does not coordinate remote hosts, network
file systems, separate container temporary directories, or other applications.

Tests declare resource names with `#[RequiresResource]`. Configuration assigns
each name to run scope or machine scope. Test classes do not contain the
deployment topology.

## Files and namespaces

The lock root is a per-user directory under `sys_get_temp_dir()`. Greenlight
hashes namespace and resource names before it makes directory names. Thus, user
input cannot escape the lock root or exceed practical path limits.

Each machine resource has:

* `definition.lock`, which records the active limit
* `slot-1.lock` through `slot-N.lock`, which represent its capacity

The files can remain after a run. File existence does not show ownership.
`flock` status controls ownership. Thus, an empty old file is harmless.

## Capacity agreement

Concurrent processes must use the same limit for a namespace and resource.
Greenlight enforces that contract with the definition file:

1. A process first attempts an exclusive definition lock.
2. Success means that no other process has a registration. The process writes
   its limit. It then changes the lock to shared and keeps it for the run.
3. If the exclusive lock is busy, the process takes a shared lock and reads the
   established limit.
4. A different limit fails before tests start.

When the last shared holder exits, a later process can take the exclusive lock
and establish a new limit. Configuration changes therefore need no cleanup or
stale-participant recovery.

## Assignment acquisition

Greenlight always attempts machine slots with `LOCK_EX | LOCK_NB`. A limit of
three creates three slot files. One locked slot consumes one unit of capacity.

A class can require more than one resource. Greenlight orders their names and
attempts one slot from each. If a resource is unavailable, Greenlight releases
each partial acquisition. This avoids deadlock. A blocked class does not keep
unrelated capacity.

The scheduler tries the oldest class first. If machine capacity blocks it,
later classes that use those resources stay behind it. Disjoint work can run.
Advisory locks do not provide a global FIFO queue between Greenlight processes.

The orchestrator owns permits for parallel runs. Worker completion, recycle,
crashes, timeouts, and failed assignment delivery all converge on the existing
resource-lease release path. An in-process run uses the same coordinator around
each class. If the orchestrator itself exits, the operating system closes its
handles and releases every lock.

## Waits and interruption

The orchestrator must continue to process worker messages while another process
holds capacity. Thus, it does not call `flock` without `LOCK_NB`. It retries
idle workers after a local capacity change and on each event-loop tick.

The in-process runner uses `LOCK_NB` polls between interruption checks. A first
Ctrl+C can stop a run that waits for machine capacity. No test starts and no
permit remains.

There is no resource-wait timeout. A holder can own a scarce dependency for
longer than a test timeout. Process exit recovers an old lock.

## Nested runs

A test can start another Greenlight command. The outer class can hold a machine
resource that the nested run also requires. In this condition, both runs would
wait for the outer test to finish.

The orchestrator includes held coordination keys in `assign`. The worker exposes
them through an internal environment variable only while that class executes.
The nested coordinator detects an overlap and fails immediately. Machine
resource leases are intentionally non-reentrant. If a nested run uses an
inherited lease, its multiple workers can exceed the capacity.
