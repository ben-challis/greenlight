<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;
use Greenlight\Tests\Fixture\Artifact\UnknownSizeFileStream;

final readonly class ArtifactUnknownSizeTest
{
    private const string SCHEME = 'greenlight-unknown-size';

    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private StreamWrappers $streamWrappers,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function aSourceWithAnUnknownSizeIsRejected(): void
    {
        $this->streamWrappers->register(self::SCHEME, UnknownSizeFileStream::class);

        $root = $this->tempDirectory->subdirectory('unknown-size');
        $configuration = new ArtifactConfiguration($root);
        $store = ArtifactStore::open($configuration, $root, 'run-unknown-size');
        $this->cleanup->defer($store->cleanup(...));
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'rejectsUnknownSize'),
            1,
            new TestArtifactBudget(),
        );

        Expect::that(static fn() => $attachments->file(
            'evidence.txt',
            self::SCHEME . '://evidence',
        ))
            ->because('an attachment source MUST report a nonnegative size')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment size could not be determined.',
            );
    }
}
