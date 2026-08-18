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

Greenlight leaves completed output in place. Cleanup and retention belong to
the user or CI system.

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

The storage layer does not redact content. Tests and plugins **MUST** remove
secrets before they add data as an attachment.

## Public and wire formats

The public interface contains `Attachments`, `Attachment`, `AttachmentKind`,
and `AttachmentRetention`. Storage keys are internal. Classes that control
staging, publication, and recovery are also internal.

JSONL version 2 requires `attachments` on `TestResult` and
`artifactsDirectory` on `RunStarted`. The worker protocol is internal and
versioned separately.
