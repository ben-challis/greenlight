<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactRetention;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class ArtifactParentResolutionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function relativeParentsWithEmptyAndDotSegmentsAreCanonical(): void
    {
        $working = $this->tempDirectory->subdirectory('parent-normalisation');
        $canonical = (string) \realpath($working);

        foreach (['./build/x', 'build//x', 'build/./x/'] as $configured) {
            $retention = ArtifactRetention::forConfiguration(new ArtifactConfiguration($configured), $working);

            expect($retention->parent)
                ->because('empty and dot segments MUST NOT make the artifact parent noncanonical')
                ->toBe($canonical . '/build/x');
        }

        $handle = ArtifactRetention::forConfiguration(new ArtifactConfiguration('./build//x/.'), $working)->begin('run-1');
        $handle->close();

        expect(\is_dir($canonical . '/build/x/run-1'))->toBeTrue();
    }

    #[Test]
    public function aSymbolicLinkAboveTheParentIsValid(): void
    {
        $working = $this->tempDirectory->subdirectory('parent-linked-ancestor');
        $target = $this->tempDirectory->subdirectory('parent-linked-ancestor-target');
        \symlink($target, $working . '/linked');

        $retention = ArtifactRetention::forConfiguration(new ArtifactConfiguration('linked/artifacts'), $working);
        $handle = $retention->begin('run-1');
        $handle->close();

        expect($retention->parent)
            ->because('a symbolic link above the parent MUST resolve to its canonical target')
            ->toBe(\realpath($target) . '/artifacts');
        expect(\is_dir($target . '/artifacts/run-1'))->toBeTrue();
    }

    #[Test]
    public function openRejectsALinkedParentBeforeTheFirstAttachment(): void
    {
        $working = $this->tempDirectory->subdirectory('parent-open-validation');
        $target = $this->tempDirectory->subdirectory('parent-open-validation-target');
        \symlink($target, $working . '/artifacts');

        expect()->calling(static fn(): ArtifactStore => ArtifactStore::open(
            new ArtifactConfiguration('artifacts'),
            $working,
            'run-1',
        ))
            ->because('an unsafe artifact parent MUST fail when the run opens its store')
            ->toThrow(AttachmentError::class, message: 'Attachment output directory contains a symbolic link.');
    }
}
