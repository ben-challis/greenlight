<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Artifact\NativeFileCopier;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Artifact\UnflushableStream;

final readonly class ArtifactCopyFlushFailureTest
{
    private const string SCHEME = 'greenlight-unflushable';

    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private StreamWrappers $streamWrappers,
    ) {}

    #[Test]
    public function aFlushFailureRejectsTheCopyAndClosesTheDestination(): void
    {
        $this->streamWrappers->register(self::SCHEME, UnflushableStream::class);
        $source = $this->tempDirectory->path() . '/copy-flush-source.txt';

        if (\file_put_contents($source, 'evidence') === false) {
            Fail::because('The test could not write its attachment source.');
        }

        UnflushableStream::reset();

        Expect::that(static fn() => new NativeFileCopier()->copy(
            $source,
            self::SCHEME . '://destination',
        ))
            ->because('an unflushed attachment copy MUST fail')
            ->toThrow(
                AttachmentError::class,
                message: 'Failed to flush the published attachment.',
            );
        Expect::that(UnflushableStream::closedStreams())
            ->because('a flush failure MUST close the destination stream')
            ->toBe(1);
    }
}
