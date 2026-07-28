<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Attribute;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class RetryTest
{
    #[Test]
    #[DataSet('invalidThrowableTypes')]
    public function invalidThrowableTypesAreRejected(string $onlyOn): void
    {
        Expect::that(
            static fn(): Retry => new Retry(1, onlyOn: $onlyOn),
        )
            ->because('a retry filter MUST name a Throwable type')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Retry onlyOn MUST name a Throwable type.',
            );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidThrowableTypes(): iterable
    {
        yield 'empty' => [''];

        yield 'non-throwable class' => [\stdClass::class];

        yield 'unknown class' => ['Example\MissingThrowable'];
    }
}
