<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Artifact\StreamWriter;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Tests\Fixture\Artifact\ZeroWriteStream;

final readonly class ArtifactStreamWriteFailureTest
{
    private const string SCHEME = 'greenlight-zero-write';

    public function __construct(private StreamWrappers $streamWrappers) {}

    #[Test]
    public function aZeroByteWriteFailsInsteadOfLooping(): void
    {
        $this->streamWrappers->register(self::SCHEME, ZeroWriteStream::class);
        $stream = \fopen(self::SCHEME . '://attachment', 'wb');

        if ($stream === false) {
            Fail::because('The test could not open the zero-write stream.');
        }

        try {
            Expect::that(static fn() => StreamWriter::writeFully($stream, 'evidence'))
                ->because('a write without progress MUST fail instead of looping')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Failed to write the complete attachment.',
                );
        } finally {
            \fclose($stream);
        }
    }
}
