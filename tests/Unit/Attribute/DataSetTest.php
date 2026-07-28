<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Attribute;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class DataSetTest
{
    #[Test]
    #[DataSet('emptyProviderIdentifiers')]
    public function emptyProviderIdentifiersAreRejected(
        string $provider,
        ?string $method,
        string $message,
    ): void {
        Expect::that(
            static fn(): DataSet => new DataSet($provider, $method),
        )
            ->because('data set provider identifiers MUST be non-empty')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, string|null, non-empty-string}>
     */
    public static function emptyProviderIdentifiers(): iterable
    {
        yield 'local provider' => [
            '',
            null,
            'Data set provider MUST NOT be empty.',
        ];

        yield 'external provider class' => [
            '',
            'rows',
            'Data set provider class MUST NOT be empty.',
        ];

        yield 'external provider method' => [
            self::class,
            '',
            'Data set provider method MUST NOT be empty.',
        ];
    }
}
