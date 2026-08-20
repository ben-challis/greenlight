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
use Greenlight\Core\Test\Cleanup;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;

final readonly class ArtifactStorageKeyTest
{
    public function __construct(
        private TempDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    #[DataSet('unsafeStorageKeys')]
    public function publicationRejectsUnsafeStorageKeys(string $case, string $storageKey): void
    {
        $root = $this->tempDirectory->subdirectory('storage-key-' . $case);
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-storage-key');
        $this->cleanup->defer($store->cleanup(...));
        $attachment = new StagedAttachment(
            'evidence.txt',
            AttachmentKind::Text,
            'text/plain',
            8,
            \hash('sha256', 'evidence'),
            1,
            'run-storage-key/evidence.txt',
            AttachmentRetention::Always,
            $storageKey,
        );
        $result = new TestResult(
            new TestId('Example\EvidenceTest', 'rejectsUnsafeStorageKey'),
            Outcome::Failed,
            0.1,
            0,
            attachments: [$attachment],
        );

        Expect::that(static fn(): TestResult => $store->publish($result))
            ->because('publication rejects an unsafe storage key')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment metadata contains an unsafe storage key.',
            );
        Expect::that(\file_exists($store->publicDirectory()))
            ->because('an unsafe storage key cannot create an output path')
            ->toBeFalse();
    }

    #[Test]
    public function discardedAttachmentsCannotEscapeTheStagingDirectory(): void
    {
        $root = $this->tempDirectory->subdirectory('unsafe-discard');
        $store = ArtifactStore::open(
            new ArtifactConfiguration($root),
            $root,
            'run-unsafe-discard',
        );
        $this->cleanup->defer($store->cleanup(...));
        $staging = $store->session()->stagingDirectory;
        $sentinelName = \basename($staging) . '-sentinel';
        $sentinel = \dirname($staging) . '/' . $sentinelName;
        $storageKey = '../' . $sentinelName;
        $this->cleanup->defer(static fn(): bool => @\unlink($sentinel));
        $attachment = new StagedAttachment(
            'evidence.txt',
            AttachmentKind::Text,
            'text/plain',
            8,
            \hash('sha256', 'evidence'),
            1,
            'run-unsafe-discard/evidence.txt',
            AttachmentRetention::OnFailure,
            $storageKey,
        );
        $result = new TestResult(
            new TestId('Example\EvidenceTest', 'rejectsUnsafeDiscard'),
            Outcome::Passed,
            0.1,
            0,
            attachments: [$attachment],
        );

        Expect::that(\file_put_contents($sentinel, 'preserve'))
            ->because('the sentinel file MUST exist before publication')
            ->toBe(8);
        Expect::that(static fn(): TestResult => $store->publish($result))
            ->because('discard MUST validate a staging coordinate before removing files')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment metadata contains an unsafe storage key.',
            );
        Expect::that(\file_get_contents($sentinel))
            ->because('unsafe metadata MUST NOT remove files outside staging')
            ->toBe('preserve');
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function unsafeStorageKeys(): iterable
    {
        yield 'absolute' => ['absolute', '/outside.txt'];
        yield 'backslash' => ['backslash', 'attempt\outside.txt'];
        yield 'traversal' => ['traversal', 'attempt/../outside.txt'];
        yield 'empty segment' => ['empty-segment', 'attempt//outside.txt'];
    }
}
