<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final readonly class AttachmentValidMediaTypeTest
{
    #[Test]
    #[DataSet('validMediaTypes')]
    public function validMediaTypesSurviveConstructionAndTheWire(string $mediaType): void
    {
        $attachment = new Attachment(
            'attachment',
            AttachmentKind::Text,
            $mediaType,
            1,
            \str_repeat('a', 64),
            1,
            'attachment.txt',
        );
        $restored = Attachment::fromWire($attachment->toWire());

        Expect::that($attachment->mediaType)
            ->because('attachment construction MUST preserve a valid media type')
            ->toBe($mediaType);
        Expect::that($restored->mediaType)
            ->because('attachment wire decoding MUST preserve a valid media type')
            ->toBe($mediaType);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function validMediaTypes(): iterable
    {
        yield 'vendor type with a structured suffix' => ['application/vnd.api+json'];
        yield 'quoted parameter containing a semicolon' => ['text/plain; note="left;right"'];
        yield 'quoted parameter containing an escaped quote' => ['text/plain; note="left\"right"'];
        yield 'quoted parameter containing an escaped backslash' => ['text/plain; note="left\\\\right"'];
    }
}
