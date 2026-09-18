<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;
use Greenlight\Tests\Fixture\Execution\Artifact\GrowingFileStream;

use function Greenlight\expect;

final readonly class ArtifactGrowingSourceTest
{
    public function __construct(private TemporaryDirectory $directory, private Cleanup $cleanup) {}

    #[Test]
    #[DataSet('initialSizes')]
    public function aGrowingSourceCannotWriteBeyondItsReservedBytes(int $initialSize): void
    {
        \stream_wrapper_register('growingattachment', GrowingFileStream::class);
        $this->cleanup->defer(static fn(): bool => \stream_wrapper_unregister('growingattachment'));
        $configuration = new ArtifactConfiguration(
            $this->directory->path() . '/published',
            maxAttachmentBytes: 8192,
            maxRunBytes: 8192,
        );
        $store = ArtifactStore::open($configuration, $this->directory->path(), 'growing-source');
        $this->cleanup->defer($store->cleanup(...));
        GrowingFileStream::$initialSize = $initialSize;
        GrowingFileStream::$stagingDirectory = $store->session()->stagingDirectory;
        GrowingFileStream::$maximumStagedBytes = 0;
        $attachments = $store->forAttempt(new TestId(self::class, __FUNCTION__), 1, new TestArtifactBudget());

        expect()->calling(static fn() => $attachments->file('growing.txt', 'growingattachment://source'))
            ->toThrow(AttachmentError::class, message: 'Attachment source "growingattachment://source" changed while it was being copied.');
        expect(GrowingFileStream::$maximumStagedBytes)->toBeLessThanOrEqual($initialSize);
        expect(\glob($store->session()->stagingDirectory . '/*/attempt-*/*.part'))->toBe([]);
        expect($attachments->collected())->toBe([]);

        $attachments->bytes('valid.bin', \str_repeat('v', 8192));

        expect($attachments->collected()[0]->sizeBytes)->toBe(8192);
    }

    /** @return iterable<string, array{int}> */
    public static function initialSizes(): iterable
    {
        yield 'empty file grows' => [0];
        yield 'file at the quota grows' => [8192];
    }
}
