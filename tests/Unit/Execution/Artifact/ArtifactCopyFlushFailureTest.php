<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Execution\Artifact\NativeFileCopier;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Execution\Artifact\UnflushableStream;

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
