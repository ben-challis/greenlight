<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Execution\Artifact\StreamWriter;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Tests\Fixture\Execution\Artifact\ZeroWriteStream;

use function Greenlight\expect;

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
            expect()->calling(static fn() => StreamWriter::writeFully($stream, 'evidence'))
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
