<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\ErrorTrap;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\NativeFileCopier;
use Greenlight\Tests\Fixture\Artifact\TrackedOpenStream;

final readonly class ArtifactCopyOpenFailureTest
{
    private const string SCHEME = 'greenlight-tracked-open';

    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function copyOpenFailuresCloseTheOtherStream(): void
    {
        if (!\stream_wrapper_register(self::SCHEME, TrackedOpenStream::class)) {
            Fail::because('The test could not register the tracked-open stream.');
        }

        $root = $this->tempDirectory->subdirectory('copy-open-failures');

        try {
            TrackedOpenStream::reset();

            $sourceWarning = null;
            Expect::that(static function () use ($root, &$sourceWarning): void {
                ErrorTrap::run(
                    static fn() => new NativeFileCopier()->copy(
                        $root . '/missing-source.txt',
                        self::SCHEME . '://destination',
                    ),
                    $sourceWarning,
                );
            })
                ->because('a missing source MUST fail the attachment copy')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Failed to copy attachment into its output directory.',
                );
            Expect::that($sourceWarning)
                ->because('a source open failure MUST not leak an engine diagnostic')
                ->toBeNull();

            Expect::that(TrackedOpenStream::closedStreams())
                ->because('a source open failure MUST close the destination stream')
                ->toBe(1);

            TrackedOpenStream::reset();

            $destinationWarning = null;
            Expect::that(static function () use ($root, &$destinationWarning): void {
                ErrorTrap::run(
                    static fn() => new NativeFileCopier()->copy(
                        self::SCHEME . '://source',
                        $root . '/missing/destination.txt',
                    ),
                    $destinationWarning,
                );
            })
                ->because('an unavailable destination MUST fail the attachment copy')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Failed to copy attachment into its output directory.',
                );
            Expect::that($destinationWarning)
                ->because('a destination open failure MUST not leak an engine diagnostic')
                ->toBeNull();

            Expect::that(TrackedOpenStream::closedStreams())
                ->because('a destination open failure MUST close the source stream')
                ->toBe(1);
        } finally {
            \stream_wrapper_unregister(self::SCHEME);
        }
    }
}
