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
    public function abstractThrowableTypesAreRejected(): void
    {
        Expect::that(
            static fn(): object => new \ReflectionClass(Retry::class)->newInstance(1, AbstractRetryFailure::class),
        )
            ->because('a retry filter MUST name an instantiable Throwable class')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Retry onlyOn MUST name an instantiable Throwable class.',
            );
    }

    #[Test]
    #[DataSet('invalidThrowableTypes')]
    public function invalidThrowableTypesAreRejected(string $onlyOn): void
    {
        Expect::that(
            static fn(): object => new \ReflectionClass(Retry::class)->newInstance(1, $onlyOn),
        )
            ->because('a retry filter MUST name an instantiable Throwable class')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Retry onlyOn MUST name an instantiable Throwable class.',
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

abstract class AbstractRetryFailure extends \Exception {}
