<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Expect\Expect;

final readonly class NullableStringListWireTest
{
    #[Test]
    public function nonNullListsValidateTheirElementTypes(): void
    {
        Expect::that(
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
