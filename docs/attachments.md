# Test attachments

Attachments keep diagnostic data with the related test result. They do not write this
data to captured stdout. Tests and worker-side plugins can attach JSON values,
text, bytes, or files that already exist.

## Attachment creation

Ask for `Greenlight\Artifact\Attachments` through constructor injection:

<!-- php-example {"example":"attachments-example-01","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Attribute\Test;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Artifact\Attachments;

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

Media types use `type/subtype` form with optional parameters. Greenlight rejects
malformed values and control characters.

Greenlight copies the content before the method returns. You can remove a
temporary source file after `file()` returns. Later changes to a value or file
do not change the attachment.

Greenlight retains attachments when the attempt fails or has an error. This
rule includes a successful attempt that a result policy changes to another
outcome. To retain an attachment from a successful attempt, set its retention to
`AttachmentRetention::Always`:

<!-- php-example {"example":"attachments-example-02","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
$attachments->text(
    'timing.txt',
    $timing,
    retention: AttachmentRetention::Always,
);
```

Each retry has separate attachments. Attachments from failed attempts stay on
the final result even if a later attempt passes. The `attempt` field identifies
the source attempt for each attachment. Greenlight discards attachments from
the successful attempt unless their retention is `always`.

## Output directory

By default, Greenlight writes retained attachments to a unique run directory
below `build/greenlight-artifacts`. A run with no retained attachments does not
create an empty directory. Change the parent directory in `greenlight.php`:

<!-- php-example {"example":"attachments-example-03","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Config\ArtifactBuilder;

return GreenlightConfig::create()
    ->artifacts(fn (ArtifactBuilder $artifacts) => $artifacts
        ->directory('build/test-evidence'));
```

Use `--artifacts-dir` to override it for one run:

```sh
vendor/bin/greenlight run --artifacts-dir=build/ci-evidence
```

Greenlight does not delete completed run directories. For local runs, remove
these directories. In CI, use the artifact retention settings.

## Metadata and names

Each attachment records its name, kind, media type, byte size, SHA-256 digest,
attempt number, retention policy, and published path. The metadata does not
include the original source path or the attachment content.

Attachment names are labels, not paths. They cannot contain directory
separators or control characters. They cannot equal `.` or `..`. Repeated names
within one attempt receive `-2`, `-3`, and later suffixes in their published
filenames. Their logical names remain unchanged in the result metadata.

Greenlight converts test IDs to slugs and hashes before it uses them in paths.

## File safety

Source files must be regular files, not symlinks. Greenlight verifies that a
source does not change during the copy operation. Published paths stay in the
configured run directory. Greenlight creates artifact files with private
permissions on supported platforms.

Greenlight does not inspect or redact attachment content. Before you attach a
value or file, remove secrets and personal data.
Apply the policy for sensitive CI artifacts to the output directory.

## Limits

The defaults are:

* 32 attachments per test
* 25 MiB per attachment
* 100 MiB across all attachments for one test
* 10,000 attachments per run
* 1 GiB across all attachments for one run

Configure the limits with `maxAttachmentsPerTest()`, `maxAttachmentSize()`,
`maxTestSize()`, `maxRunAttachments()`, and `maxRunSize()` on
`ArtifactBuilder`. Size methods accept values such as `10M` and `2G`. A limit
violation fails the active test with an attachment error. Greenlight does not
truncate attachment content.

Greenlight coordinates run limits through private shared staging. Thus, they
apply across parallel workers. Per-test limits include all attempts, even when
Greenlight discards some attachments later. Greenlight releases run quota when
it discards an attachment.

## Plugins

`$context->attachments` gives the same attempt-owned object to
`BeforeTestSubscriber::beforeTest()` and `AfterTestSubscriber::afterTest()`.
A plugin can attach
data before the test or after it examines the result.

A retry decider receives attachment metadata on the `TestResult`, but it cannot
read the content through the result. See [writing plugins](plugins.md) for the
plugin interfaces.

## Reporters and CI

The `tty` and `plain` reporters print the paths of retained attachments. JUnit
adds `[[ATTACHMENT|path]]` markers to the test case's `system-out`. GitHub
annotations include attachment paths and an artifact directory notice. TeamCity
emits artifact metadata and publishes the run directory.

JSONL includes attachment metadata and reports the run directory in
`run-started.artifactsDirectory`. See the [JSONL
schema](architecture/jsonl.md).

For other CI systems, use a post-test step that always runs. Upload the reported
run directory from this step.
