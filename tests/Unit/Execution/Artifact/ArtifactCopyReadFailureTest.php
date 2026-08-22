<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Execution\Artifact\NativeFileCopier;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Execution\Artifact\UnreadableCopySourceStream;

final readonly class ArtifactCopyReadFailureTest
{
    private const string SCHEME = 'greenlight-unreadable-copy-source';

    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private StreamWrappers $streamWrappers,
    ) {}

    #[Test]
    public function aReadFailureRejectsTheCopyAndClosesTheSource(): void
    {
        $this->streamWrappers->register(self::SCHEME, UnreadableCopySourceStream::class);
        UnreadableCopySourceStream::reset();
        $destination = $this->tempDirectory->path() . '/destination.txt';

        Expect::that(static fn() => new NativeFileCopier()->copy(
            self::SCHEME . '://source',
            $destination,
        ))
            ->because('an unreadable attachment source MUST fail its copy')
            ->toThrow(
                AttachmentError::class,
                message: 'Failed to read attachment staging content.',
            );
        Expect::that(UnreadableCopySourceStream::closedStreams())
            ->because('a read failure MUST close the source stream')
            ->toBe(1);
    }
}
