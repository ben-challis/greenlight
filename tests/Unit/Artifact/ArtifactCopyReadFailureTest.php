<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\FileCopier;
use Greenlight\Tests\Fixture\Artifact\UnreadableCopySourceStream;

final readonly class ArtifactCopyReadFailureTest
{
    private const string SCHEME = 'greenlight-unreadable-copy-source';

    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aReadFailureRejectsTheCopyAndClosesTheSource(): void
    {
        if (!\stream_wrapper_register(self::SCHEME, UnreadableCopySourceStream::class)) {
            Fail::because('The test could not register the unreadable copy-source stream.');
        }

        try {
            UnreadableCopySourceStream::reset();
            $destination = $this->tempDirectory->path() . '/destination.txt';

            Expect::that(static fn() => FileCopier::copy(
                self::SCHEME . '://source',
                $destination,
            ))
                ->because('an unreadable attachment source MUST fail its copy')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Failed to read attachment staging content.',
                )
                ->and(UnreadableCopySourceStream::closedStreams())
                ->because('a read failure MUST close the source stream')
                ->toBe(1);
        } finally {
            \stream_wrapper_unregister(self::SCHEME);
        }
    }
}
