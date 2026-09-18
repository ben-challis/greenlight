<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Wire;

use Greenlight\Attribute\Test;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Internal\Wire\Wire;

use function Greenlight\expect;

final readonly class NullableStringListWireTest
{
    #[Test]
    public function nonNullListsValidateTheirElementTypes(): void
    {
        expect()->calling(
            static fn(): ?array => Wire::nullableListOfStrings(
                ['field' => ['valid', 42]],
                'field',
            ),
        )
            ->because('a non-null nullable string list MUST validate each element')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "field" must be a list of strings, got int.',
            );
    }
}
