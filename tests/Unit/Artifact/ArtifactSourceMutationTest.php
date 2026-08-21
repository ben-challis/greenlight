<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Artifact\ChangingFileStream;
use Greenlight\Tests\Fixture\Artifact\FailingFileReadStream;

final readonly class ArtifactSourceMutationTest
{
    private const string SCHEME = 'greenlight-changing-file';

    private const string FAILING_READ_SCHEME = 'greenlight-failing-file-read';

    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private StreamWrappers $streamWrappers,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function aSourceThatChangesDuringCopyIsRejectedAndReleased(): void
    {
        $this->streamWrappers->register(self::SCHEME, ChangingFileStream::class);

        $root = $this->tempDirectory->subdirectory('source-mutation');
        $configuration = new ArtifactConfiguration(
            $root,
            maxRunAttachments: 1,
        );
        $store = ArtifactStore::open($configuration, $root, 'run-source-mutation');
        $this->cleanup->defer($store->cleanup(...));
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'rejectsChangingSource'),
            1,
            new TestArtifactBudget(),
        );
        $source = self::SCHEME . '://evidence';

        Expect::that(static fn() => $attachments->file('evidence.txt', $source))
            ->because('a file attachment source must stay unchanged while Greenlight copies it')
            ->toThrow(
                AttachmentError::class,
                message: \sprintf(
                    'Attachment source "%s" changed while it was being copied.',
                    $source,
                ),
            );

        $attachments->text('replacement.txt', 'replacement');

        Expect::that($attachments->collected())
            ->because('a rejected source releases its run quota')
            ->toHaveCount(1);
        Expect::that($attachments->collected()[0]->name)
            ->toBe('replacement.txt');
    }

    #[Test]
    public function aSourceThatFailsDuringCopyIsRejectedAndReleased(): void
    {
        $this->streamWrappers->register(self::FAILING_READ_SCHEME, FailingFileReadStream::class);

        $root = $this->tempDirectory->subdirectory('source-read-failure');
        $configuration = new ArtifactConfiguration(
            $root,
            maxRunAttachments: 1,
        );
        $store = ArtifactStore::open($configuration, $root, 'run-source-read-failure');
        $this->cleanup->defer($store->cleanup(...));
        $id = new TestId('Example\EvidenceTest', 'rejectsUnreadableSource');
        $attachments = $store->forAttempt(
            $id,
            1,
            new TestArtifactBudget(),
        );
        $source = self::FAILING_READ_SCHEME . '://evidence';

        Expect::that(static fn() => $attachments->file('evidence.txt', $source))
            ->because('a file attachment read failure MUST reject the incomplete copy')
            ->toThrow(
                AttachmentError::class,
                message: \sprintf('Attachment source "%s" could not be read.', $source),
            );

        $failedPath = $store->session()->stagingDirectory
            . '/' . ArtifactStore::testDirectory($id)
            . '/attempt-1/01-evidence.txt';

        Expect::that(\file_exists($failedPath))
            ->because('a failed source read MUST remove the incomplete staging file')
            ->toBeFalse();
        Expect::that(\file_exists($failedPath . '.part'))
            ->because('a failed source read MUST remove the partial staging file')
            ->toBeFalse();
        Expect::that(\file_exists($failedPath . '.meta.json'))
            ->because('a failed source read MUST not leave recovery metadata')
            ->toBeFalse();

        $attachments->text('replacement.txt', 'replacement');

        Expect::that($attachments->collected())
            ->because('a failed source read MUST release its run quota')
            ->toHaveCount(1);
        Expect::that($attachments->collected()[0]->name)
            ->toBe('replacement.txt');
    }
}
