<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Artifact\StreamWriter;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Tests\Fixture\Artifact\WarningWriteStream;

final readonly class ArtifactStreamWriteDiagnosticTest
{
    private const string SCHEME = 'greenlight-artifact-write-warning';

    public function __construct(private StreamWrappers $streamWrappers) {}

    #[Test]
    public function aStreamWriteDiagnosticIsContainedByTheAttachmentError(): void
    {
        $this->streamWrappers->register(self::SCHEME, WarningWriteStream::class);
        $stream = \fopen(self::SCHEME . '://attachment', 'wb');

        if ($stream === false) {
            Fail::because('Greenlight did not open the warning stream.');
        }

        $diagnostic = null;
        \set_error_handler(static function (int $severity, string $message) use (&$diagnostic): bool {
            $diagnostic = $message;

            return true;
        });

        try {
            Expect::that(static fn() => StreamWriter::writeFully($stream, 'evidence'))
                ->because('a stream diagnostic becomes only an attachment error')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Failed to write the complete attachment.',
                );
        } finally {
            \restore_error_handler();
            \fclose($stream);
        }

        Expect::that($diagnostic)
            ->because('attachment write diagnostics MUST NOT reach the host error handler')
            ->toBeNull();
    }
}
