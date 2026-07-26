# Artifact storage architecture

Attachment content stays on disk. Workers send attachment metadata to the
orchestrator, which keeps binary data out of stdout, events, and worker protocol
frames.

## Run directories

Each run has an eventual public output directory and a private staging
directory in the system temporary directory. The orchestrator passes both paths
to workers as part of the assignment. Staging is created when the first
attachment is added; the public directory is created only if the run publishes
one.

An attachment is copied into staging when the test or plugin adds it. Structured
values are serialized at that point. Later changes to the source value or file
cannot change the attachment.

The staged file has an opaque storage key. Its public metadata contains the
logical name, kind, media type, size, SHA-256 digest, attempt number, retention
policy, and eventual published path.

## Publication

Before a `TestFinished` event reaches reporters, the process handling the event
decides retention from the terminal result. Retained content is copied to a
temporary file beside its destination and renamed into place atomically. The
public `TestResult` contains the published metadata without the storage key.

Attachments from failed attempts are retained across retries. A passing attempt
retains only attachments marked `always`. Sealing an attempt prevents further
attachments but does not apply retention. The process publishing the terminal
result decides retention after retries, per-test and class-scope teardown,
plugin outcome transformation, and result policy. Discarding an attachment
removes its staged content and releases its run quota.

Greenlight leaves completed output in place. Cleanup and retention belong to
the user or CI system.

## Crash recovery

Each completed staged file has a JSON sidecar written through a temporary file
and atomic rename. If a worker exits unexpectedly, the orchestrator reads
completed sidecars for the active test and publishes those attachments before
it emits the synthetic crash result. Partial copies have no completed sidecar
and are removed during cleanup.

Once a test has staged evidence, a small marker records later retry attempts so
a synthetic crash result reports the right attempt count even when the crashing
attempt added nothing. Recovery accepts only storage keys that resolve within
the staging directory.

## Limits

A locked `.quota` file in private staging coordinates the attachment count and
byte total across workers. Per-test count and byte limits are tracked across
every attempt for the test.

The worker protocol carries metadata rather than base64 content. This keeps
attachments outside its 8 MiB frame limit and avoids loading large files into
reporters.

## Path safety

Logical names are validated before they become filenames. Test identifiers are
slugged and hashed, and repeated names get a numeric suffix. Source files must
be regular files and cannot be symlinks. Greenlight compares the file's size
and modification time before and after copying and rejects a detected change.

Destination and recovery paths must remain under their configured roots.
Attachment files and internal metadata use private permissions on supported
platforms.

The storage layer does not redact content. Tests and plugins must remove secrets
before attaching data.

## Public and wire formats

The public interface is `Attachments`, `Attachment`, `AttachmentKind`, and
`AttachmentRetention`. Storage keys and the classes used for staging,
publication, and recovery are internal.

JSONL version 2 requires `attachments` on `TestResult` and
`artifactsDirectory` on `RunStarted`. The worker protocol is internal and
versioned separately.
