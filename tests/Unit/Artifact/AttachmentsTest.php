<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Core\Artifact\StagedAttachment;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\StagedAttachments;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class AttachmentsTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function stagesPublishesAndHashesEveryAttachmentKind(): void
    {
        $root = $this->tempDirectory->subdirectory('published');
        $configuration = new ArtifactConfiguration($root);
        $store = ArtifactStore::open($configuration, $root, 'run-1');
        $attachments = $store->forAttempt(new TestId('Example\EvidenceTest', 'fails'), 1, new TestArtifactBudget());
        $source = $this->tempDirectory->path() . '/source.bin';
        \file_put_contents($source, "\x00file");

        $attachments->value('request.json', ['ok' => true]);
        $attachments->text('response.txt', 'hello');
        $attachments->bytes('packet.bin', "\x00\xFF");
        $attachments->text('response.txt', 'duplicate name');
        $attachments->file('copy.bin', $source);
        \unlink($source);

        $retained = $attachments->seal();
        $published = $store->publish(new TestResult(
            new TestId('Example\EvidenceTest', 'fails'),
            Outcome::Failed,
            0.1,
            0,
            attachments: $retained,
        ));

        Expect::that($published->attachments)->because('stages publishes and hashes every attachment kind')->toHaveCount(5);
        Expect::that($published->attachments[1]->name)->because('stages publishes and hashes every attachment kind')->toBe('response.txt');
        Expect::that($published->attachments[3]->name)->because('stages publishes and hashes every attachment kind')->toBe('response.txt');
        Expect::that($published->attachments[3]->path)->because('stages publishes and hashes every attachment kind')->toContain('response-2.txt');

        foreach ($published->attachments as $attachment) {
            $path = $this->absolute($root, $attachment->path);
            Expect::that(\is_file($path))
                ->because(\sprintf('published attachment "%s" exists as a file', $attachment->path))
                ->toBeTrue();
            Expect::that(\hash_file('sha256', $path))
                ->because(\sprintf('published attachment "%s" has its recorded SHA-256 digest', $attachment->path))
                ->toBe($attachment->sha256);
        }

        Expect::that((string) \file_get_contents($this->absolute($root, $published->attachments[0]->path)))->because('stages publishes and hashes every attachment kind')
            ->toBe("{\"ok\":true}\n");
        Expect::that((string) \file_get_contents($this->absolute($root, $published->attachments[4]->path)))->because('stages publishes and hashes every attachment kind')
            ->toBe("\x00file");

        $store->cleanup();
    }

    #[Test]
    public function passingAttemptsDiscardOnFailureAttachmentsButKeepAlways(): void
    {
        $root = $this->tempDirectory->subdirectory('retention');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-2');
        $attachments = $store->forAttempt(new TestId('Example\EvidenceTest', 'passes'), 1, new TestArtifactBudget());
        $attachments->text('discarded.txt', 'discard me');
        $attachments->text('kept.txt', 'keep me', retention: AttachmentRetention::Always);

        $published = $store->publish(new TestResult(
            new TestId('Example\EvidenceTest', 'passes'),
            Outcome::Passed,
            0.1,
            0,
            attachments: $attachments->seal(),
        ));

        Expect::that($published->attachments)->because('passing attempts discard on failure attachments but keep always')->toHaveCount(1);
        Expect::that($published->attachments[0]->name)->because('passing attempts discard on failure attachments but keep always')->toBe('kept.txt');

        $store->cleanup();
    }

    #[Test]
    public function outputDirectoryIsCreatedOnlyWhenEvidenceIsPublished(): void
    {
        $root = $this->tempDirectory->subdirectory('lazy-output');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-lazy');
        $output = $store->publicDirectory();

        Expect::that(\file_exists($output))->because('output directory is created only when evidence is published')->toBeFalse();

        $id = new TestId('Example\EvidenceTest', 'passes');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());

        Expect::that(\file_exists($output))->because('output directory is created only when evidence is published')->toBeFalse();

        $attachments->text('discarded.txt', 'discard me');
        $published = $store->publish(new TestResult(
            $id,
            Outcome::Passed,
            0.1,
            0,
            attachments: $attachments->seal(),
        ));

        Expect::that($published->attachments)->because('output directory is created only when evidence is published')->toBe([])
            ->and(\file_exists($output))->toBeFalse();

        $store->cleanup();
    }

    /**
     * @param \Closure(StagedAttachments): void $write
     */
    #[Test]
    #[DataSet('invalidAttachmentWrites')]
    public function invalidAttachmentWritesGiveExactGuidance(\Closure $write, string $message): void
    {
        $root = $this->tempDirectory->subdirectory('invalid-writes');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-invalid');
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'invalid'),
            1,
            new TestArtifactBudget(),
        );

        try {
            Expect::that(static fn() => $write($attachments))
                ->because('an invalid attachment write gives exact guidance')
                ->toThrow(AttachmentError::class, message: $message);
        } finally {
            $store->cleanup();
        }
    }

    /**
     * @return iterable<string, array{\Closure(StagedAttachments): void, string}>
     */
    public static function invalidAttachmentWrites(): iterable
    {
        yield 'sealed attempt' => [
            static function (StagedAttachments $attachments): void {
                $attachments->seal();
                $attachments->text('late.txt', 'too late');
            },
            'Attachments cannot be added after the test attempt has finished.',
        ];

        yield 'invalid media type' => [
            static fn(StagedAttachments $attachments) => $attachments->text('evidence.txt', 'body', 'invalid'),
            'Attachment media type "invalid" is invalid.',
        ];

        yield 'cyclic JSON value' => [
            static function (StagedAttachments $attachments): void {
                $value = [];
                $value['self'] = &$value;
                $attachments->value('cyclic.json', $value);
            },
            'Attachment value cannot be encoded as JSON: Recursion detected.',
        ];

        yield 'invalid UTF-8 JSON value' => [
            static fn(StagedAttachments $attachments) => $attachments->value(
                'invalid-utf8.json',
                "\xB1",
            ),
            'Attachment value cannot be encoded as JSON: Malformed UTF-8 characters, possibly incorrectly encoded.',
        ];

        yield 'nonfinite JSON value' => [
            static fn(StagedAttachments $attachments) => $attachments->value(
                'nonfinite.json',
                \INF,
            ),
            'Attachment value cannot be encoded as JSON: Inf and NaN cannot be JSON encoded.',
        ];
    }

    #[Test]
    public function unsafeNamesSymlinksAndLimitsFailLoudly(): void
    {
        $root = $this->tempDirectory->subdirectory('safety');
        $configuration = new ArtifactConfiguration(
            $root,
            maxAttachmentsPerTest: 1,
            maxAttachmentBytes: 4,
            maxTestBytes: 4,
            maxRunAttachments: 1,
            maxRunBytes: 4,
        );
        $store = ArtifactStore::open($configuration, $root, 'run-3');
        $id = new TestId('Example\EvidenceTest', 'limits');
        $budget = new TestArtifactBudget();
        $attachments = $store->forAttempt($id, 1, $budget);

        Expect::that(static fn() => $attachments->text('../secret', 'x'))->because('invalid names, symbolic links, and exceeded limits cause errors')
            ->toThrow(AttachmentError::class);
        Expect::that(static fn() => $attachments->bytes('large.bin', '12345'))->because('invalid names, symbolic links, and exceeded limits cause errors')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment size 5 exceeds the limit of 4 bytes.',
            );

        $attachments->text('one.txt', '1234');

        Expect::that(static fn() => $attachments->text('two.txt', 'x'))->because('invalid names, symbolic links, and exceeded limits cause errors')
            ->toThrow(
                AttachmentError::class,
                message: 'This test has reached the limit of 1 attachments.',
            );
        $retry = $store->forAttempt($id, 2, $budget);
        Expect::that(static fn() => $retry->text('retry.txt', 'x'))->because('invalid names, symbolic links, and exceeded limits cause errors')
            ->toThrow(
                AttachmentError::class,
                message: 'This test has reached the limit of 1 attachments.',
            );
        $runLimited = $store->forAttempt(new TestId('Example\EvidenceTest', 'run-limit'), 1, new TestArtifactBudget());
        Expect::that(static fn() => $runLimited->text('other.txt', 'x'))->because('invalid names, symbolic links, and exceeded limits cause errors')
            ->toThrow(
                AttachmentError::class,
                message: 'This run has reached the limit of 1 attachments.',
            );

        $source = $this->tempDirectory->path() . '/target.txt';
        $link = $this->tempDirectory->path() . '/link.txt';
        \file_put_contents($source, 'data');
        \symlink($source, $link);
        $other = $store->forAttempt(new TestId('Example\EvidenceTest', 'symlink'), 1, new TestArtifactBudget());

        Expect::that(static fn() => $other->file('link.txt', $link))->because('invalid names, symbolic links, and exceeded limits cause errors')
            ->toThrow(
                AttachmentError::class,
                message: \sprintf(
                    'Attachment source "%s" is a symbolic link. Use a source path that is not a symbolic link.',
                    $link,
                ),
            );

        $store->cleanup();
    }

    #[Test]
    public function aggregateByteLimitsReportExactValues(): void
    {
        $testRoot = $this->tempDirectory->subdirectory('test-byte-limit');
        $testConfiguration = new ArtifactConfiguration(
            $testRoot,
            maxAttachmentsPerTest: 10,
            maxAttachmentBytes: 10,
            maxTestBytes: 4,
            maxRunAttachments: 10,
            maxRunBytes: 10,
        );
        $testStore = ArtifactStore::open($testConfiguration, $testRoot, 'run-test-limit');
        $testAttachments = $testStore->forAttempt(
            new TestId('Example\EvidenceTest', 'testByteLimit'),
            1,
            new TestArtifactBudget(),
        );
        $testAttachments->text('one.txt', '1234');

        Expect::that(static fn() => $testAttachments->text('two.txt', 'x'))
            ->toThrow(
                AttachmentError::class,
                message: 'Attachments for this test exceed the limit of 4 bytes.',
            );

        $testStore->cleanup();

        $runRoot = $this->tempDirectory->subdirectory('run-byte-limit');
        $runConfiguration = new ArtifactConfiguration(
            $runRoot,
            maxAttachmentsPerTest: 10,
            maxAttachmentBytes: 10,
            maxTestBytes: 10,
            maxRunAttachments: 10,
            maxRunBytes: 4,
        );
        $runStore = ArtifactStore::open($runConfiguration, $runRoot, 'run-run-limit');
        $runStore->forAttempt(
            new TestId('Example\EvidenceTest', 'first'),
            1,
            new TestArtifactBudget(),
        )->text('one.txt', '1234');
        $runAttachments = $runStore->forAttempt(
            new TestId('Example\EvidenceTest', 'second'),
            1,
            new TestArtifactBudget(),
        );

        Expect::that(static fn() => $runAttachments->text('two.txt', 'x'))
            ->toThrow(
                AttachmentError::class,
                message: 'Attachments for this run exceed the limit of 4 bytes.',
            );

        $runStore->cleanup();
    }

    #[Test]
    public function rejectsAParentSegmentInARelativeOutputDirectoryExactly(): void
    {
        $workingDirectory = $this->tempDirectory->subdirectory('relative-output');

        Expect::that(static fn(): ArtifactStore => ArtifactStore::open(
            new ArtifactConfiguration('../outside'),
            $workingDirectory,
            'run-relative',
        ))->toThrow(
            AttachmentError::class,
            message: 'Keep a relative attachment output directory inside the working directory.',
        );
    }

    #[Test]
    public function rejectsASymlinkedStagingDirectoryExactly(): void
    {
        $root = $this->tempDirectory->subdirectory('symlinked-staging');
        $target = $root . '/target';
        $staging = $root . '/staging';
        \mkdir($target);
        \symlink($target, $staging);
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            new ArtifactConfiguration($root . '/published'),
        );

        Expect::that(static fn() => $store->recordAttempt(
            new TestId('Example\EvidenceTest', 'symlinkedStaging'),
            1,
        ))->toThrow(
            AttachmentError::class,
            message: 'Attachment staging directory is unsafe.',
        );
    }

    #[Test]
    public function reportsAnExistingStagingPartExactly(): void
    {
        $root = $this->tempDirectory->subdirectory('existing-staging-part');
        $staging = $root . '/staging';
        $storageKey = 'Example-EvidenceTest/attempt-1/01-evidence.txt';
        \mkdir(\dirname($staging . '/' . $storageKey), 0o777, true);
        \file_put_contents($staging . '/' . $storageKey . '.part', 'occupied');

        $configuration = new ArtifactConfiguration($root . '/published');
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            $configuration,
        );

        Expect::that(static fn() => $store->stageBytes(
            'evidence',
            'evidence.txt',
            $storageKey,
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        ))->toThrow(
            AttachmentError::class,
            message: 'Greenlight did not create the attachment staging file.',
        );
    }

    #[Test]
    public function reportsMetadataAndAttemptRecordFailuresExactly(): void
    {
        $root = $this->tempDirectory->subdirectory('metadata-failures');
        $staging = $root . '/staging';
        \mkdir($staging);
        $configuration = new ArtifactConfiguration($root . '/published');
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            $configuration,
        );

        Expect::that(static fn() => $store->stageBytes(
            'evidence',
            'evidence.txt',
            \str_repeat('a', 250),
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        ))->toThrow(
            AttachmentError::class,
            message: 'Greenlight did not write attachment recovery metadata.',
        );

        $id = new TestId('Example\EvidenceTest', 'attemptRecord');
        $testDirectory = $staging . '/' . ArtifactStore::testDirectory($id);
        \mkdir($testDirectory, 0o777, true);
        \mkdir($testDirectory . '/.attempt');

        Expect::that(static fn() => $store->recordAttempt($id, 2))
            ->toThrow(
                AttachmentError::class,
                message: 'Greenlight did not finalize the current test attempt record.',
            );
    }

    #[Test]
    public function reportsAnAttachmentCleanupFailureExactly(): void
    {
        $root = $this->tempDirectory->subdirectory('cleanup-failure');
        $staging = $root . '/staging';
        $storageKey = 'test/attempt-1/01-evidence.txt';
        \mkdir($staging . '/' . $storageKey . '.meta.json', 0o777, true);
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            new ArtifactConfiguration($root . '/published'),
        );
        $attachment = new StagedAttachment(
            'evidence.txt',
            AttachmentKind::Text,
            'text/plain',
            1,
            \hash('sha256', 'x'),
            1,
            $root . '/published/run-1/' . $storageKey,
            AttachmentRetention::OnFailure,
            $storageKey,
        );

        Expect::that(static fn() => $store->discard($attachment))
            ->toThrow(
                AttachmentError::class,
                message: 'Greenlight did not remove attachment recovery metadata.',
            );
    }

    #[Test]
    public function reportsAnExistingAttachmentOutputExactly(): void
    {
        $root = $this->tempDirectory->subdirectory('existing-output');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-output');
        $id = new TestId('Example\EvidenceTest', 'fails');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('evidence.txt', 'evidence');
        $staged = $attachments->seal()[0];
        $destination = $store->publicDirectory() . '/' . $staged->storageKey;
        \mkdir(\dirname($destination), 0o777, true);
        \file_put_contents($destination, 'occupied');

        Expect::that(static fn(): TestResult => $store->publish(new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: [$staged],
        )))->toThrow(
            AttachmentError::class,
            message: 'An attachment output path already exists.',
        );

        $store->cleanup();
    }

    #[Test]
    public function rejectsTamperedStagingContentExactly(): void
    {
        $root = $this->tempDirectory->subdirectory('tampered-staging');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-tampered');
        $id = new TestId('Example\EvidenceTest', 'fails');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('evidence.txt', 'original');
        $staged = $attachments->seal()[0];
        \file_put_contents(
            $store->session()->stagingDirectory . '/' . $staged->storageKey,
            'tampered',
        );

        Expect::that(static fn(): TestResult => $store->publish(new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: [$staged],
        )))->toThrow(
            AttachmentError::class,
            message: 'Attachment staging content does not match its metadata.',
        );

        $store->cleanup();
    }

    #[Test]
    public function completedEvidenceCanBeRecoveredAfterAWorkerCrash(): void
    {
        $root = $this->tempDirectory->subdirectory('recovery');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-4');
        $id = new TestId('Example\EvidenceTest', 'crashes');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('last-response.txt', 'completed before crash');

        $recovered = $store->recover(new TestResult($id, Outcome::Errored, 0.0, 0));

        Expect::that($recovered->attachments)->because('completed evidence can be recovered after a worker crash')->toHaveCount(1)
            ->and($recovered->attachments[0]->name)->toBe('last-response.txt')
            ->and(\is_file($recovered->attachments[0]->path))->toBeTrue();

        $store->cleanup();
    }

    #[Test]
    public function corruptRecoveryMetadataDoesNotHideCompletedEvidence(): void
    {
        $root = $this->tempDirectory->subdirectory('corrupt-recovery');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-corrupt');
        $id = new TestId('Example\EvidenceTest', 'crashes');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('completed.txt', 'complete before crash');
        $testDirectory = $store->session()->stagingDirectory . '/' . ArtifactStore::testDirectory($id);
        \file_put_contents($testDirectory . '/broken.meta.json', '{"name":');

        $recovered = $store->recover(new TestResult($id, Outcome::Errored, 0.0, 0));

        Expect::that($recovered->attachments)
            ->because('corrupt recovery metadata MUST NOT hide completed evidence')
            ->toHaveCount(1)
            ->and($recovered->attachments[0]->name)
            ->toBe('completed.txt')
            ->and(\is_file($recovered->attachments[0]->path))
            ->toBeTrue();

        $store->cleanup();
    }

    #[Test]
    public function crashRecoveryRestoresTheLatestAttemptWithoutAnAttachment(): void
    {
        $root = $this->tempDirectory->subdirectory('attempt-recovery');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-5');
        $id = new TestId('Example\EvidenceTest', 'crashesOnRetry');
        $budget = new TestArtifactBudget();
        $first = $store->forAttempt($id, 1, $budget);
        $first->text('first-attempt.txt', 'failed before retry');
        $first->seal();
        $store->forAttempt($id, 2, $budget);

        $recovered = $store->recover(new TestResult($id, Outcome::Errored, 0.0, 0));

        Expect::that($recovered->attempts)->because('crash recovery restores the latest attempt without an attachment')->toBe(2)
            ->and($recovered->attachments)->toHaveCount(1)
            ->and($recovered->attachments[0]->attempt)->toBe(1);

        $store->cleanup();
    }

    private function absolute(string $workingDirectory, string $publishedPath): string
    {
        return \str_starts_with($publishedPath, '/')
            ? $publishedPath
            : \rtrim($workingDirectory, '/') . '/' . $publishedPath;
    }
}
