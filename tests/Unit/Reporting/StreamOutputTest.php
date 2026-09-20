<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Reporting\StreamOutput;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Reporting\PartialWriteStream;
use Greenlight\Tests\Support\MemoryStream;

use function Greenlight\expect;

final readonly class StreamOutputTest
{
    private const string PARTIAL_WRITE_SCHEME = 'greenlight-partial-write';

    public function __construct(
        private StreamWrappers $streamWrappers,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function writesAccumulateOnTheStream(): void
    {
        $stream = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stream));

        $output = new StreamOutput($stream);
        $output->write('first ');
        $output->write('second');

        \rewind($stream);

        expect(\stream_get_contents($stream))->because('writes accumulate on the stream')->toBe('first second');
    }

    #[Test]
    public function aStreamWriteFailureBecomesAReportGenerationFailed(): void
    {
        $stream = ErrorTrap::run(static fn() => \fopen('php://memory', 'r'));

        expect($stream)
            ->because('Greenlight MUST open the read-only in-memory stream.')
            ->not()->toBeFalse();
        $this->cleanup->defer(static fn(): bool => \fclose($stream));

        $output = new StreamOutput($stream);

        expect()->calling(static function () use ($output): void {
            $output->write('cannot be written');
        })
            ->because('a stream write failure becomes a reporting error')
            ->toThrow(
                ReportGenerationFailed::class,
                message: 'Greenlight did not write reporter output to the stream.',
            );
    }

    #[Test]
    public function aClosedStreamThrowableBecomesAReportGenerationFailed(): void
    {
        $stream = MemoryStream::open();
        MemoryStream::close($stream);

        $output = new StreamOutput($stream);

        expect()->calling(static fn() => $output->write('cannot be written'))
            ->because('a native stream throwable MUST not escape the reporting seam')
            ->toThrow(
                static function (ReportGenerationFailed $error): void {
                    expect($error->getPrevious())
                        ->because('the reporting error MUST preserve the native stream error')
                        ->toBeInstanceOf(\TypeError::class);
                },
            );
    }

    #[Test]
    public function partialWritesAreRetriedUntilTheReporterTextIsComplete(): void
    {
        $stream = $this->openPartialWriteStream('partial');

        new StreamOutput($stream)->write('complete reporter output');

        expect(PartialWriteStream::contents())
            ->because('a short stream write MUST NOT truncate reporter output')
            ->toBe('complete reporter output');
    }

    #[Test]
    public function aStalledPartialWriteBecomesAReportGenerationFailed(): void
    {
        $stream = $this->openPartialWriteStream('stalled');

        $output = new StreamOutput($stream);

        expect()->calling(static fn() => $output->write('cannot make progress'))
            ->because('a zero-byte write MUST stop instead of retrying without a limit')
            ->toThrow(
                ReportGenerationFailed::class,
                message: 'Greenlight did not write reporter output to the stream.',
            );
    }

    /**
     * @return resource
     */
    private function openPartialWriteStream(string $path)
    {
        $this->streamWrappers->register(self::PARTIAL_WRITE_SCHEME, PartialWriteStream::class);

        $stream = ErrorTrap::run(static fn() => \fopen(self::PARTIAL_WRITE_SCHEME . '://' . $path, 'wb'));

        expect($stream)
            ->because('Greenlight MUST open the partial-write stream.')
            ->not()->toBeFalse();
        $this->cleanup->defer(static fn(): bool => \fclose($stream));

        return $stream;
    }
}
