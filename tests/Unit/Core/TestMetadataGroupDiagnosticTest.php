<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Expect\Expect;

final readonly class TestMetadataGroupDiagnosticTest
{
    #[Test]
    public function emptyGroupNamesGiveExactGuidance(): void
    {
        Expect::that(static fn(): TestMetadata => new TestMetadata(
            'App\PaymentTest',
            'chargesCard',
            ['payments', ''],
        ))
            ->because('invalid group metadata MUST identify the empty name')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Group names cannot be empty.',
            );
    }
}
