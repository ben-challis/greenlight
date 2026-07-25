# Test attachments

Tests and worker-side plugins can attach structured values, text, binary data,
and files to the current test attempt. Greenlight copies the content immediately
and keeps it out of captured stdout, so HTTP exchanges, database diagnostics,
subprocess logs, screenshots, traces, and similar evidence remain associated
with the result that produced them.

## Attaching evidence

Ask for `Greenlight\Core\Artifact\Attachments` through constructor injection:

```php
use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Core\Artifact\Attachments;

final readonly class CheckoutTest
{
    public function __construct(private Attachments $attachments) {}

    #[Test]
    public function submitsAnOrder(): void
    {
        $response = $this->client->post('/orders');

        $this->attachments->value('response.json', [
            'status' => $response->status(),
            'headers' => $response->headers(),
        ]);
        $this->attachments->text('subprocess.log', $this->process->output());
        $this->attachments->bytes('trace.bin', $this->trace);
        $this->attachments->file('screenshot.png', $this->screenshotPath);
    }
}
```

`value()` encodes the value as pretty-printed JSON. `text()` and `bytes()` accept
an optional media type. `file()` copies a regular file and infers a media type
from its extension when possible. Input is copied during the call, so a
temporary source may be removed immediately afterwards.

By default, evidence is retained only when the attempt fails, errors, or is
changed from passing by result policy. Use
`AttachmentRetention::Always` for evidence that should survive a passing
attempt:

```php
$attachments->text(
    'timing.txt',
    $timing,
    retention: AttachmentRetention::Always,
);
```

Each retry is a distinct attempt. Evidence from failed attempts is retained
even if a later attempt passes, and its `attempt` metadata identifies where it
came from. Default-retention evidence from the passing attempt is discarded.

## Files and metadata

Every run gets a unique directory below `build/greenlight-artifacts` by default.
Override the parent directory in configuration or for one run:

```php
use Greenlight\Config\ArtifactBuilder;

return GreenlightConfig::create()
    ->artifacts(fn (ArtifactBuilder $artifacts) => $artifacts
        ->directory('build/test-evidence'));
```

```sh
greenlight run --artifacts-dir=build/ci-evidence
```

Result metadata contains the attachment's logical name, kind, media type, byte
size, SHA-256 digest, attempt number, retention policy, and published path. It
does not contain the original source path or the content. Names are labels, not
paths: they cannot contain directory separators, control characters, `.` or
`..`. Duplicate names in one attempt receive deterministic `-2`, `-3`, and
later suffixes in their published filenames while retaining the original
logical name in metadata. Worker and test identifiers are slugged and hashed to
avoid cross-worker collisions.

Source files must be regular files, not symlinks. Greenlight verifies that a
source does not change while it is copied. Published paths remain inside the
configured run directory. Artifact files are created with private permissions
where the platform supports them.

Attachments are not a secret store. Greenlight does not inspect or redact their
contents. Remove credentials, cookies, authorization headers, database
passwords, personal data, and other sensitive values before attaching them.
Treat the output directory as sensitive CI data and choose its access and
retention policy accordingly.

## Limits

The defaults are:

| Limit | Default |
| --- | ---: |
| attachments per test | 32 |
| one attachment | 25 MiB |
| all attachments for one test | 100 MiB |
| attachments per run | 10,000 |
| all attachments for one run | 1 GiB |

Configure them with `maxAttachmentsPerTest()`, `maxAttachmentSize()`,
`maxTestSize()`, `maxRunAttachments()`, and `maxRunSize()` on
`ArtifactBuilder`. Size methods accept values such as `10M` and `2G`. Exceeding
a limit fails the active test with an attachment error; content is never
silently truncated.

Run-wide limits are coordinated through the shared artifact directory, so they
also apply to parallel workers. Unpublished temporary files are removed when a
worker or run exits. If a worker crashes after completing a copy, the
orchestrator recovers the sidecar metadata and publishes that evidence with the
synthetic crash result.

## Plugins and reporters

`TestContext::$attachments` exposes the same attempt-owned object to
`TestLifecycleSubscriber::beforeTest()` and `afterTest()`. Plugins can therefore
capture integration state before execution or add evidence after seeing a
failed result. A retry decider receives attachment metadata on the
`TestResult`; content remains out of process.

The `tty` and `plain` reporters print paths for retained evidence. JUnit adds
`[[ATTACHMENT|path]]` markers to the testcase's `system-out`. GitHub annotations
include paths and finish with an artifact-directory notice. TeamCity emits test
artifact metadata and publishes the run directory. JSONL carries the metadata
described in the [version 1 schema](architecture/jsonl.md).

In other CI systems, upload the run directory in an always-running post-test
step. The directory is announced by `run-started.artifactsDirectory` in JSONL,
so automation does not need to reconstruct the unique run name. Greenlight does
not delete old completed run directories; let local cleanup or the CI artifact
retention setting govern them.
