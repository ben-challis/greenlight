<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Expect\Expect;

final readonly class TestMetadataSkipUnlessDiagnosticTest
{
    #[Test]
    public function invalidArgumentNamesItsType(): void
    {
        Expect::that(static fn(): TestMetadata => new TestMetadata(
            'App\PaymentTest',
            'chargesCard',
            skipUnlessArguments: [['nested']],
        ))
            ->because('invalid skip-unless metadata MUST name the argument type')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Skip-unless arguments must be scalars or null, got array.',
            );
    }
}
