# Test attachments

Attachments keep diagnostic data with the test result that produced it, without
writing the data to captured stdout. Tests and worker-side plugins can attach
JSON values, text, bytes, or existing files.

## Adding attachments

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

`value()` encodes its value as JSON. `text()` and `bytes()` accept an optional
media type. `file()` copies a regular file and detects its media type when
possible.

Greenlight copies the content before the method returns. A temporary source file
can be removed as soon as `file()` returns, and later changes to a value or file
do not affect the attachment.

Attachments are retained when the attempt fails or errors. This includes a
passing attempt changed to another outcome by result policy. To retain an
attachment from a passing attempt, set its retention to
`AttachmentRetention::Always`:

```php
$attachments->text(
    'timing.txt',
    $timing,
    retention: AttachmentRetention::Always,
);
```

Each retry has its own attachments. Attachments from failed attempts remain on
the final result even if a later attempt passes. The `attempt` field identifies
the attempt that produced each attachment. Attachments from the passing attempt
are discarded unless their retention is `always`.

## Output directory

Retained attachments from each run go into a unique directory below
`build/greenlight-artifacts` by default. Runs with no retained attachments do
not create an empty directory. Change the parent directory in `greenlight.php`:

```php
use Greenlight\Config\ArtifactBuilder;

return GreenlightConfig::create()
    ->artifacts(fn (ArtifactBuilder $artifacts) => $artifacts
        ->directory('build/test-evidence'));
```

Use `--artifacts-dir` to override it for one run:

```sh
greenlight run --artifacts-dir=build/ci-evidence
```

Greenlight does not delete completed run directories. Remove them locally or use
the artifact retention settings in CI.

## Metadata and names

Each attachment records its name, kind, media type, byte size, SHA-256 digest,
attempt number, retention policy, and published path. The metadata does not
include the original source path or the attachment content.

Attachment names are labels, not paths. They cannot contain directory
separators, control characters, `.` or `..`. Repeated names within one attempt
receive `-2`, `-3`, and later suffixes in their published filenames. Their
logical names remain unchanged in the result metadata.

Test identifiers are slugged and hashed before Greenlight uses them in paths.

## File safety

Source files must be regular files, not symlinks. Greenlight verifies that a
source does not change while it is copied. Published paths remain inside the
configured run directory. Artifact files are created with private permissions
where the platform supports them.

Greenlight does not inspect or redact attachment contents. Remove secrets and
personal data before attaching a value or file. Access to the output directory
should follow the same policy as other sensitive CI artifacts.

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
a limit fails the active test with an attachment error. Greenlight does not
truncate attachment content.

Run-wide limits are coordinated through private shared staging, so they
apply across parallel workers. Per-test limits include all attempts, even when
some attachments are later discarded. Run quota is released when an attachment
is discarded.

## Plugins

`$context->attachments` exposes the same attempt-owned object to
`TestLifecycleSubscriber::beforeTest()` and `afterTest()`. A plugin can attach
data before the test or after inspecting its result.

A retry decider receives attachment metadata on the `TestResult`, but it cannot
read the content through the result. See [writing plugins](plugins.md) for the
plugin interfaces.

## Reporters and CI

The `tty` and `plain` reporters print the paths of retained attachments. JUnit
adds `[[ATTACHMENT|path]]` markers to the test case's `system-out`. GitHub
annotations include attachment paths and an artifact directory notice. TeamCity
emits artifact metadata and publishes the run directory.

JSONL includes attachment metadata and reports the run directory in
`run-started.artifactsDirectory`. See the [JSONL version 2
schema](architecture/jsonl.md).

For other CI systems, upload the reported run directory from a post-test step
that runs even when the test command fails.
