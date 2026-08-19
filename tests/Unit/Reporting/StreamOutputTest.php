<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\StreamWrapperSandbox;
use Greenlight\Reporting\Output\StreamOutput;
use Greenlight\Reporting\ReportingError;
use Greenlight\Tests\Fixture\Reporting\PartialWriteStream;

final readonly class StreamOutputTest
{
    private const string PARTIAL_WRITE_SCHEME = 'greenlight-partial-write';

    public function __construct(private StreamWrapperSandbox $streamWrappers) {}

    #[Test]
    public function writesAccumulateOnTheStream(): void
    {
        $stream = \fopen('php://memory', 'r+');

        if ($stream === false) {
            throw new \RuntimeException('Could not open an in-memory stream.');
        }

        $output = new StreamOutput($stream);
        $output->write('first ');
        $output->write('second');

        \rewind($stream);

        Expect::that(\stream_get_contents($stream))->because('writes accumulate on the stream')->toBe('first second');

        \fclose($stream);
    }

    #[Test]
    public function aStreamWriteFailureBecomesAReportingError(): void
    {
        $stream = \fopen('php://memory', 'r');

        if ($stream === false) {
            throw new \RuntimeException('Could not open a read-only in-memory stream.');
        }

        try {
            $output = new StreamOutput($stream);

            Expect::that(static function () use ($output): void {
                $output->write('cannot be written');
            })
                ->because('a stream write failure becomes a reporting error')
                ->toThrow(
                    ReportingError::class,
                    message: 'Greenlight did not write reporter output to the stream.',
                );
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function partialWritesAreRetriedUntilTheReporterTextIsComplete(): void
    {
        $stream = $this->openPartialWriteStream('partial');

        try {
            new StreamOutput($stream)->write('complete reporter output');

            Expect::that(PartialWriteStream::contents())
                ->because('a short stream write MUST NOT truncate reporter output')
                ->toBe('complete reporter output');
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function aStalledPartialWriteBecomesAReportingError(): void
    {
        $stream = $this->openPartialWriteStream('stalled');

        try {
            $output = new StreamOutput($stream);

            Expect::that(static fn() => $output->write('cannot make progress'))
                ->because('a zero-byte write MUST stop instead of retrying without a limit')
                ->toThrow(
                    ReportingError::class,
                    message: 'Greenlight did not write reporter output to the stream.',
                );
        } finally {
            \fclose($stream);
        }
    }

    /**
     * @return resource
     */
    private function openPartialWriteStream(string $path)
    {
        $this->streamWrappers->register(self::PARTIAL_WRITE_SCHEME, PartialWriteStream::class);

        $stream = \fopen(self::PARTIAL_WRITE_SCHEME . '://' . $path, 'wb');

        if ($stream === false) {
            throw new \RuntimeException('Greenlight did not open the partial-write stream.');
        }

        return $stream;
    }
}
