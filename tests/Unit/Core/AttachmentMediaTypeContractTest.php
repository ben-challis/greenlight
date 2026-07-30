<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Expect\Expect;

final class AttachmentMediaTypeContractTest
{
    #[Test]
    #[DataSet('invalidMediaTypes')]
    public function constructionAndWireDecodingRejectInvalidMediaTypes(string $mediaType): void
    {
        Expect::that(static fn(): Attachment => new Attachment(
            'attachment',
            AttachmentKind::Text,
            $mediaType,
            1,
            \str_repeat('a', 64),
            1,
            'attachment.txt',
        ))
            ->because('attachment construction MUST reject an invalid media type')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Attachment metadata is invalid.',
            );

        Expect::that(static fn(): Attachment => Attachment::fromWire([
            'name' => 'attachment',
            'kind' => AttachmentKind::Text->value,
            'mediaType' => $mediaType,
            'sizeBytes' => 1,
            'sha256' => \str_repeat('a', 64),
            'attempt' => 1,
            'path' => 'attachment.txt',
        ]))
            ->because('attachment wire decoding MUST reject an invalid media type')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Attachment metadata is invalid.',
            );
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function invalidMediaTypes(): iterable
    {
        yield 'missing subtype' => ['text'];
        yield 'null byte' => ["text/plain; note=\"before\0after\""];
        yield 'tab' => ["text/plain; note=\"before\tafter\""];
        yield 'line feed' => ["text/plain; note=\"before\nafter\""];
        yield 'carriage return' => ["text/plain; note=\"before\rafter\""];
        yield 'delete' => ["text/plain; note=\"before\x7Fafter\""];
    }
}
