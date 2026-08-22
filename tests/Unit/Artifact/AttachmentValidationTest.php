<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class AttachmentValidationTest
{
    #[Test]
    #[DataSet('invalidMetadata')]
    public function invalidMetadataIsRejected(
        string $name,
        string $mediaType,
        int $sizeBytes,
        string $sha256,
        int $attempt,
        string $path,
    ): void {
        Expect::that(static function () use ($name, $mediaType, $sizeBytes, $sha256, $attempt, $path): void {
            new Attachment(
                $name,
                AttachmentKind::Text,
                $mediaType,
                $sizeBytes,
                $sha256,
                $attempt,
                $path,
            );
        })
            ->because('invalid attachment metadata MUST be rejected at the boundary')
            ->toThrow(\InvalidArgumentException::class, message: 'Attachment metadata is invalid.');
    }

    /**
     * @return iterable<string, array{string, string, int, string, int, string}>
     */
    public static function invalidMetadata(): iterable
    {
        $hash = \str_repeat('a', 64);

        yield 'empty name' => ['', 'text/plain', 1, $hash, 1, 'attachment.txt'];
        yield 'empty media type' => ['attachment', '', 1, $hash, 1, 'attachment.txt'];
        yield 'negative size' => ['attachment', 'text/plain', -1, $hash, 1, 'attachment.txt'];
        yield 'invalid hash' => ['attachment', 'text/plain', 1, \str_repeat('a', 63), 1, 'attachment.txt'];
        yield 'zero attempt' => ['attachment', 'text/plain', 1, $hash, 0, 'attachment.txt'];
        yield 'negative attempt' => ['attachment', 'text/plain', 1, $hash, -1, 'attachment.txt'];
        yield 'empty path' => ['attachment', 'text/plain', 1, $hash, 1, ''];
        yield 'null byte path' => ['attachment', 'text/plain', 1, $hash, 1, "attachment\0.txt"];
    }
}
