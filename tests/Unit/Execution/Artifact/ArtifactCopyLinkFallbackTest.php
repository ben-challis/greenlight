<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Execution\Artifact\NativeFileCopier;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Execution\Artifact\LinkRejectingSourceStream;

use function Greenlight\expect;

final readonly class ArtifactCopyLinkFallbackTest
{
    private const string SCHEME = 'greenlight-link-rejecting-source';

    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private StreamWrappers $streamWrappers,
    ) {}

    #[Test]
    public function aRejectedLinkOnTheSameDeviceFallsBackToACopy(): void
    {
        $directory = $this->tempDirectory->subdirectory('rejected-link');
        $stat = \stat($directory);
        if ($stat === false) {
            throw new \RuntimeException('The test did not stat its directory.');
        }
        $this->streamWrappers->register(self::SCHEME, LinkRejectingSourceStream::class);
        LinkRejectingSourceStream::serve($stat['dev'], 'linked content');
        $destination = $directory . '/destination.txt';

        $digest = new NativeFileCopier()->copy(self::SCHEME . '://source', $destination);

        expect(\file_get_contents($destination))
            ->because('a link that the filesystem rejects MUST fall back to a copy')
            ->toBe('linked content');
        expect($digest)->toBe(\hash('sha256', 'linked content'));
    }

    #[Test]
    public function anUnreadableLinkedFileRejectsThePublication(): void
    {
        $directory = $this->tempDirectory->subdirectory('unreadable-link');
        $source = $directory . '/source.txt';
        \file_put_contents($source, 'staged content');
        \chmod($source, 0o000);
        $destination = $directory . '/destination.txt';

        try {
            expect()->calling(static fn() => new NativeFileCopier()->copy($source, $destination))
                ->because('a linked file that the copier cannot hash MUST fail the publication')
                ->toThrow(AttachmentError::class, message: 'Failed to read the published attachment.');
        } finally {
            \chmod($source, 0o600);
        }
    }
}
