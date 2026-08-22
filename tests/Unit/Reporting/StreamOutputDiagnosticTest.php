<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Reporting\Output\StreamOutput;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Tests\Fixture\Reporting\PartialWriteStream;

final readonly class StreamOutputDiagnosticTest
{
    private const string SCHEME = 'greenlight-stream-output-warning';

    public function __construct(private StreamWrappers $streamWrappers) {}

    #[Test]
    public function aStreamWriteDiagnosticIsContainedByTheReportGenerationFailed(): void
    {
        $this->streamWrappers->register(self::SCHEME, PartialWriteStream::class);
        $stream = \fopen(self::SCHEME . '://warning', 'wb');

        if ($stream === false) {
            Fail::because('Greenlight did not open the warning stream.');
        }

        $diagnostic = null;
        \set_error_handler(static function (int $severity, string $message) use (&$diagnostic): bool {
            $diagnostic = $message;

            return true;
        });

        try {
            $output = new StreamOutput($stream);

            Expect::that(static fn() => $output->write('cannot be written'))
                ->because('a stream diagnostic becomes only a reporting error')
                ->toThrow(
                    ReportGenerationFailed::class,
                    message: 'Greenlight did not write reporter output to the stream.',
                );
        } finally {
            \restore_error_handler();
            \fclose($stream);
        }

        Expect::that($diagnostic)
            ->because('reporter write diagnostics MUST NOT reach the host error handler')
            ->toBeNull();
    }
}
