<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\FileCopier;
use Greenlight\Tests\Fixture\Artifact\FailingFileReadStream;

final readonly class ArtifactCopyReadFailureTest
{
    private const string SCHEME = 'greenlight-copy-read-failure';

    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aReadFailureRejectsTheIncompleteCopy(): void
    {
        if (!\stream_wrapper_register(self::SCHEME, FailingFileReadStream::class)) {
            Fail::because('The test could not register the failing-read stream.');
        }

        $destination = $this->tempDirectory->path() . '/destination.txt';

        try {
            Expect::that(static fn() => FileCopier::copy(
                self::SCHEME . '://source',
                $destination,
            ))
                ->because('a source read failure MUST reject an incomplete published attachment')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Failed to read attachment staging content.',
                );
        } finally {
            \stream_wrapper_unregister(self::SCHEME);
        }
    }
}
