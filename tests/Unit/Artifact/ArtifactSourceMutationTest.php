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
use Greenlight\Tests\Fixture\Artifact\ChangingFileStream;

final readonly class ArtifactSourceMutationTest
{
    private const string SCHEME = 'greenlight-changing-file';

    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aSourceThatChangesDuringCopyIsRejectedAndReleased(): void
    {
        if (!\stream_wrapper_register(self::SCHEME, ChangingFileStream::class)) {
            throw new \RuntimeException('Greenlight did not register the changing-file stream.');
        }

        $store = null;

        try {
            $root = $this->tempDirectory->subdirectory('source-mutation');
            $configuration = new ArtifactConfiguration(
                $root,
                maxRunAttachments: 1,
            );
            $store = ArtifactStore::open($configuration, $root, 'run-source-mutation');
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
                ->toHaveCount(1)
                ->and($attachments->collected()[0]->name)
                ->toBe('replacement.txt');
        } finally {
            $store?->cleanup();
            \stream_wrapper_unregister(self::SCHEME);
        }
    }
}
