<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Runner\Artifact\NativeFileCopier;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Artifact\TrackedOpenStream;

final readonly class ArtifactCopyOpenFailureTest
{
    private const string SCHEME = 'greenlight-tracked-open';

    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private StreamWrappers $streamWrappers,
    ) {}

    #[Test]
    public function aDestinationOpenFailureClosesTheSource(): void
    {
        $this->streamWrappers->register(self::SCHEME, TrackedOpenStream::class);
        $root = $this->tempDirectory->subdirectory('copy-open-failures');

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
    }

    #[Test]
    public function aMissingSourceDoesNotCreateTheDestination(): void
    {
        $root = $this->tempDirectory->subdirectory('missing-copy-source');
        $destination = $root . '/destination.txt';

        Expect::that(static fn() => new NativeFileCopier()->copy(
            $root . '/missing-source.txt',
            $destination,
        ))
            ->because('a missing source MUST fail before the destination is opened')
            ->toThrow(
                AttachmentError::class,
                message: 'Failed to copy attachment into its output directory.',
            );
        Expect::that(\file_exists($destination))
            ->because('a source open failure MUST not leave an empty destination')
            ->toBeFalse();
    }
}
