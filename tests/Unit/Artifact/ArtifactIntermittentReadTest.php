<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Tests\Fixture\Artifact\IntermittentFileStream;

final readonly class ArtifactIntermittentReadTest
{
    private const string SCHEME = 'greenlight-intermittent-file';

    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function anEmptyReadBeforeEofDoesNotTruncateTheAttachment(): void
    {
        if (!\stream_wrapper_register(self::SCHEME, IntermittentFileStream::class)) {
            Fail::because('The test could not register the intermittent-file stream.');
        }

        $store = null;

        try {
            $root = $this->tempDirectory->subdirectory('intermittent-file');
            $configuration = new ArtifactConfiguration($root);
            $store = ArtifactStore::open($configuration, $root, 'run-intermittent-file');
            $attachments = $store->forAttempt(
                new TestId('Example\EvidenceTest', 'keepsReading'),
                1,
                new TestArtifactBudget(),
            );

            $attachments->file('evidence.txt', self::SCHEME . '://evidence');
            $attachment = $attachments->collected()[0];

            Expect::that([$attachment->sizeBytes, $attachment->sha256])
                ->because('an empty read before EOF MUST NOT truncate an attachment')
                ->toBe([8, \hash('sha256', 'evidence')]);
        } finally {
            $store?->cleanup();
            \stream_wrapper_unregister(self::SCHEME);
        }
    }
}
