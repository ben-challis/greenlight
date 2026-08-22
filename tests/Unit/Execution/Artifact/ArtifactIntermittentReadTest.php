<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;
use Greenlight\Tests\Fixture\Execution\Artifact\IntermittentFileReadStream;

final readonly class ArtifactIntermittentReadTest
{
    private const string SCHEME = 'greenlight-intermittent-file-read';

    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private StreamWrappers $streamWrappers,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function anEmptyReadBeforeContentDoesNotTruncateTheAttachment(): void
    {
        $this->streamWrappers->register(self::SCHEME, IntermittentFileReadStream::class);

        $root = $this->tempDirectory->subdirectory('source-intermittent-read');
        $store = ArtifactStore::open(
            new ArtifactConfiguration($root),
            $root,
            'run-source-intermittent-read',
        );
        $this->cleanup->defer($store->cleanup(...));
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
    }
}
