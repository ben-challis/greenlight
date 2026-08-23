# Artifact storage architecture

Attachment content stays on disk. Workers send attachment metadata to the
orchestrator, which keeps binary data out of stdout, events, and worker protocol
frames.

## Run directories

Each run has a possible public output directory. It also has a private staging
directory in the system temporary directory. The orchestrator gives both paths
to workers in the assignment. Greenlight creates staging when a test or plugin
adds the first attachment. It creates the public directory only when the run
publishes an attachment.

When a test or plugin adds an attachment, Greenlight copies it into staging.
Greenlight serializes structured values at this time. Later source changes
cannot change the attachment.

The staged file has an opaque storage key. Its public metadata contains the
logical name, kind, media type, size, SHA-256 digest, attempt number, retention
policy, and eventual published path.

## Publication

Before a `TestFinished` event reaches reporters, the process that handles it
decides retention from the terminal result. The process copies retained content
to a temporary file beside the destination. It then renames the file into place
atomically. The public `TestResult` contains the published metadata without the
storage key.

The terminal result retains attachments from failed attempts across retries. A
successful attempt retains only attachments with the `always` value. A sealed
attempt cannot receive more attachments. This seal does not apply retention.

The publication process decides retention after all result changes. These
changes include retries, teardown, plugin transformations, and result policy.
When the process discards an attachment, it deletes the staged content and
releases its run quota.

Greenlight leaves completed output in place unless configuration enables run
retention. Automatic retention runs after normal run completion. The
`artifacts:prune` command supports explicit maintenance and dry-run output.

Each public run directory contains versioned ownership metadata and a lifecycle
lock. Active processes hold the lock. An unlocked active record identifies an
incomplete run that can be recovered.

A completed record contains the completion time and an exact file manifest.
The manifest has each relative path, byte count, and SHA-256 digest. Greenlight
does not prune a directory when its content does not match the manifest.

Pruning uses one lock in the canonical artifact parent. It claims a selected
run with an atomic rename before deletion. Concurrent Greenlight processes can
safely apply the same policy.

Age has first precedence, then count, then total bytes. Each limit selects the
oldest eligible completed run first. Completion time and run ID give the stable
order. A future completion time is not old for the age policy.

The current automatic run is not eligible. Unknown, active, incomplete,
changed, malformed, and future-version directories are not eligible. A
directory with a symbolic link is not eligible.

Retention failures are advisory. They do not change a test result or run exit
code. A failure that prevents publication of the current run remains fatal.

## Crash recovery

Greenlight writes a JSON sidecar for each complete staged file. It uses a
temporary file and an atomic rename. If a worker exits unexpectedly, the
orchestrator reads complete sidecars for the active test. It publishes those
attachments before it emits the synthetic crash result. Greenlight removes
partial copies, which have no complete sidecar, during cleanup.

After a test has staged evidence, a small marker records later retry attempts.
Thus, a synthetic crash result has the correct attempt count. This also applies
when a worker crashes during an attempt that has no attachment. Recovery accepts
only storage keys that resolve inside the staging directory.

## Limits

A locked `.quota` file in private staging coordinates attachment and byte
totals across workers. Greenlight tracks per-test count and byte limits across
every attempt for the test.

Attachment content therefore stays outside the protocol's 8 MiB frame limit.
Reporters do not load large attachment files.

## Path safety

Greenlight validates logical names before it uses them as file names. It
converts each test ID to a slug and a hash. It adds a numeric suffix to repeated
names. Source files **MUST** be regular files and **MUST NOT** be symbolic
links.

Greenlight compares the file size and modification time before and after the
copy. It rejects the attachment if either value changes.

Destination and recovery paths **MUST** stay below their configured roots.
Attachment files and internal metadata use private permissions on platforms
that support these permissions.

Pruning **MUST** keep the configured parent. It **MUST NOT** follow a symbolic
link or delete content outside the canonical parent.

The storage layer does not redact content. Tests and plugins **MUST** remove
secrets before they add data as an attachment.

## Public and wire formats

The public interface contains `Attachments`, `Attachment`, `AttachmentKind`,
and `AttachmentRetention`. Storage keys are internal. Classes that control
staging, publication, and recovery are also internal.

JSONL versions 2 and 3 require `attachments` on `TestResult` and
`artifactsDirectory` on `RunStarted`. The worker protocol is internal and
versioned separately.

CI platform retention remains authoritative after artifact upload. Local
Greenlight retention only controls files on the runner filesystem.
