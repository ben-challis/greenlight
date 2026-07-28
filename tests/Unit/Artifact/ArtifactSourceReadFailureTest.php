<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Tests\Fixture\Artifact\UnreadableFileStream;

final readonly class ArtifactSourceReadFailureTest
{
    private const string SCHEME = 'greenlight-unreadable-file';

    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aSourceReadFailureIsReportedAndReleasesQuota(): void
    {
        if (!\stream_wrapper_register(self::SCHEME, UnreadableFileStream::class)) {
            throw new \RuntimeException('Greenlight did not register the unreadable-file stream.');
        }

        $store = null;

        try {
            $root = $this->tempDirectory->subdirectory('source-read-failure');
            $configuration = new ArtifactConfiguration(
                $root,
                maxRunAttachments: 1,
            );
            $store = ArtifactStore::open($configuration, $root, 'run-source-read-failure');
            $attachments = $store->forAttempt(
                new TestId('Example\EvidenceTest', 'rejectsUnreadableSource'),
                1,
                new TestArtifactBudget(),
            );
            $source = self::SCHEME . '://evidence';

            Expect::that(static fn() => $attachments->file('evidence.txt', $source))
                ->because('a failed source read MUST report the attachment source')
                ->toThrow(
                    AttachmentError::class,
                    message: \sprintf(
                        'Attachment source "%s" could not be read.',
                        $source,
                    ),
                );

            $attachments->text('replacement.txt', 'replacement');

            Expect::that($attachments->collected())
                ->because('a failed source read MUST release its run quota')
                ->toHaveCount(1)
                ->and($attachments->collected()[0]->name)
                ->toBe('replacement.txt');
        } finally {
            $store?->cleanup();
            \stream_wrapper_unregister(self::SCHEME);
        }
    }
}
