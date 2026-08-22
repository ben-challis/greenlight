<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Execution\Artifact\StreamWriter;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Tests\Fixture\Execution\Artifact\PartialWriteStream;

final readonly class ArtifactStreamPartialWriteTest
{
    private const string SCHEME = 'greenlight-partial-write';

    public function __construct(private StreamWrappers $streamWrappers) {}

    #[Test]
    public function partialWritesContinueUntilEveryByteIsWritten(): void
    {
        $this->streamWrappers->register(self::SCHEME, PartialWriteStream::class);
        $stream = \fopen(self::SCHEME . '://attachment', 'wb');

        if ($stream === false) {
            Fail::because('The test could not open the partial-write stream.');
        }

        try {
            StreamWriter::writeFully($stream, 'evidence');

            Expect::that(PartialWriteStream::$written)
                ->because('a partial write MUST continue from the first unwritten byte')
                ->toBe('evidence');
            Expect::that(PartialWriteStream::$writes)
                ->because('the fixture accepts at most two bytes from each write')
                ->toBe(4);
        } finally {
            \fclose($stream);
        }
    }
}
