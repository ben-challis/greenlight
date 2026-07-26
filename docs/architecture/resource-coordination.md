# Machine resource coordination

Greenlight coordinates scarce resources between its processes on one machine.
[Configuration](../configuration.md#machine-scoped-resource-limits) documents
the public interface. This page documents the implementation constraints.

## Decision

Machine-scoped resource limits use local advisory file locks in the class
scheduler. They do not require a daemon, database, or distributed lock service.

Machine resource coordination requires the same OS user, coordination
namespace, and system temporary directory. It does not coordinate remote
machines, network file systems, separate container temporary directories, or
other applications.

Tests declare resource names with `#[RequiresResource]`. Configuration assigns
each name either a run-scoped or machine-scoped resource limit. Tests do not
specify where Greenlight coordinates the limit.

## Files and namespaces

Greenlight stores lock files in a separate directory for each OS user under
`sys_get_temp_dir()`. It hashes namespace and resource names before it makes
directory names. This keeps user input inside the lock root and limits path
length.

Each machine resource has:

* `definition.lock`, which records the active limit
* `slot-1.lock` through `slot-N.lock`, which represent its capacity

The files can remain after a run. File existence does not show ownership. Only
an active `flock()` lock shows ownership. An empty old file is harmless.

## Capacity agreement

Concurrent processes **MUST** use the same limit for a coordination namespace
and resource. Greenlight enforces this requirement with the definition file:

1. A process first attempts an exclusive definition lock.
2. If no other process has a registration, the process writes its limit. It
   then changes the lock to shared and keeps it for the run.
3. If the exclusive lock is busy, the process takes a shared lock and reads the
   established limit.
4. If the limit differs, Greenlight fails before tests start.

After the last process releases its shared lock, a later process can set a new
limit. This process does not require file cleanup or recovery for old
participants.

## Permit acquisition

Greenlight always tries to lock machine resource slots with
`LOCK_EX | LOCK_NB`. A limit of three creates three slot files. One locked slot
uses one unit of capacity.

A class can require more than one resource. Greenlight sorts the resource names
and tries to lock one slot for each name. If a resource is unavailable,
Greenlight releases all slots in that attempt. This order prevents deadlock.
The class does not keep unrelated capacity while it waits.

The scheduler tries the oldest queued class first. If machine capacity blocks
that class, later classes that use those resources stay behind it. Classes that
use different resources can run. Advisory file locks cannot enforce one FIFO
queue across Greenlight processes.

The orchestrator owns machine resource permits for parallel runs. These events
use the same resource-lease release path:

* Worker completion
* Worker replacement
* Worker crashes
* Worker timeouts
* Failed assignment delivery

An in-process run uses the same machine resource coordinator for each class. If
the orchestrator exits, the OS closes its handles and releases all locks.

## Waits and interruption

The orchestrator **MUST** continue to process worker messages while another
process holds capacity. It always uses `LOCK_NB` when it calls `flock()`. It
retries idle workers after a local capacity change and on each event-loop tick.

The in-process runner polls with `LOCK_NB` between interruption checks. The
first Ctrl+C can stop a run that waits for machine capacity. No test starts and
no machine resource permit remains.

Greenlight does not use a timeout for a resource wait. A process can hold a
scarce dependency for longer than a test timeout. When the process exits, the
OS releases the lock.

## Nested runs

A test can start another Greenlight command. The outer class can hold a machine
resource that the nested run also requires. Without an overlap check, the
nested run and outer test would wait for each other.

The orchestrator includes held coordination keys in `assign`. The worker sets
an internal environment variable only while that class runs. The nested
machine resource coordinator rejects an overlap immediately. Machine resource
permits are not reentrant. If a nested run inherited a permit, its workers could
exceed the capacity.
