# Artifact storage architecture

This record defines how per-test attachments cross Greenlight's worker boundary
without placing arbitrary binary data in events or stdout.

## Decision

Attachment content is copied immediately into a private, run-scoped staging
directory shared by the orchestrator and workers. A `TestResult` carries only
immutable metadata and an internal opaque storage key. Before a
`TestFinished` event reaches reporters, the owning process atomically moves
retained content into the public run directory and removes the storage key from
the public result.

This makes the test attempt the owner of attachment lifetime while keeping the
orchestrator the authority for publication. It also keeps protocol frames small:
the worker protocol carries metadata rather than base64 payloads, and JSONL
version two makes the published attachment metadata part of the result contract.

Each completed staged file has an atomic JSON sidecar. If a worker dies, the
orchestrator reads only sidecars whose storage keys resolve inside the staging
root, publishes evidence for the in-flight test, and then synthesizes the crash
result. Incomplete copies have no completed sidecar and are discarded.

## Consequences

* Structured values are serialized at attachment time, and files are copied at
  attachment time. Later mutation of either input cannot alter evidence.
* Binary content never passes through JSON. Metadata does, including byte size
  and SHA-256 for integrity checks.
* Failed retry attempts retain evidence. A passing final attempt retains only
  evidence explicitly marked `always`.
* Passing results can carry attachments; this is necessary for `always`
  retention and is represented by JSONL version two.
* Run-wide byte and count limits require a small locked quota file shared by
  workers. Per-test limits remain attempt-local.
* Completed output is intentionally not cleaned by Greenlight. CI or the user
  owns retention after the run.

## Safety boundaries

Logical names are validated and converted to slug-and-hash filenames. Source
paths and storage keys are private implementation data and are not serialized
to reporters. File sources must be regular non-symlink files and are checked
for mutation while copied. Destination and recovery paths are resolved beneath
known roots.

Greenlight provides path containment and private filesystem permissions, not
content redaction. The caller is responsible for removing secrets and regulated
data before attachment.

## Protocol schemas

JSONL version two requires `attachments` on `TestResult` and
`artifactsDirectory` on `RunStarted`. The worker protocol remains an internal,
independently versioned implementation detail.

The public API consists of `Attachments`, `Attachment`,
`AttachmentKind`, and `AttachmentRetention`. Storage keys, staging layout,
publication, and recovery classes remain internal and may change.

## Alternatives rejected

Embedding base64 content in results would multiply memory use, exceed the
8 MiB worker frame limit, and force every reporter to handle large values.
Writing only after a failure would lose evidence whose source resource has
already been disposed. Writing directly to final paths would expose incomplete
files and make crash recovery ambiguous.

## Open product questions

The first version deliberately leaves three policies to callers and CI:

* whether completed local runs should gain an age- or count-based cleanup
  command;
* whether a future remote artifact-store plugin should replace filesystem
  publication or consume the published event stream;
* whether suites need a global mode that retains every default attachment,
  rather than marking selected attachments `always`.

None changes the version-two metadata contract. Compression, content-addressed
deduplication, and inline previews can likewise be added behind the storage and
reporter seams if real-world artifact volume justifies them.
