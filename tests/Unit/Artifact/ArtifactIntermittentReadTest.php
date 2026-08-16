<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Tests\Fixture\Artifact\IntermittentFileReadStream;

final readonly class ArtifactIntermittentReadTest
{
    private const string SCHEME = 'greenlight-intermittent-file-read';

    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function anEmptyReadBeforeContentDoesNotTruncateTheAttachment(): void
    {
        if (!\stream_wrapper_register(self::SCHEME, IntermittentFileReadStream::class)) {
            throw new \RuntimeException('Greenlight did not register the intermittent-file-read stream.');
        }

        $store = null;

        try {
            $root = $this->tempDirectory->subdirectory('source-intermittent-read');
            $store = ArtifactStore::open(
                new ArtifactConfiguration($root),
                $root,
                'run-source-intermittent-read',
            );
            $attachments = $store->forAttempt(
                new TestId('Example\EvidenceTest', 'copiesAfterEmptyRead'),
                1,
                new TestArtifactBudget(),
            );

            $attachments->file('evidence.txt', self::SCHEME . '://evidence', 'text/plain');
            $collected = $attachments->collected();

            Expect::that($collected)
                ->because('the intermittent source MUST produce one staged attachment')
                ->toHaveCount(1);

            $attachment = $collected[0];
            $stagedPath = $store->session()->stagingDirectory . '/' . $attachment->storageKey;

            Expect::that(\file_get_contents($stagedPath))
                ->because('a transient empty source read MUST not truncate the staged attachment')
                ->toBe('evidence');
            Expect::that($attachment->sizeBytes)
                ->toBe(8);
            Expect::that($attachment->sha256)
                ->toBe(\hash('sha256', 'evidence'));
        } finally {
            $store?->cleanup();
            \stream_wrapper_unregister(self::SCHEME);
        }
    }
}
