<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\StreamWrapperSandbox;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\NativeFileCopier;
use Greenlight\Tests\Fixture\Artifact\UnflushableStream;

final readonly class ArtifactCopyFlushFailureTest
{
    private const string SCHEME = 'greenlight-unflushable';

    public function __construct(
        private TempDirectory $tempDirectory,
        private StreamWrapperSandbox $streamWrappers,
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
