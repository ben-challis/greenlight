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
    public function throwableFiltersDoNotNeedToBeInstantiable(): void
    {
        Expect::that(new Retry(1, \Throwable::class)->onlyOn)
            ->because('the Throwable interface is a valid retry type filter')
            ->toBe(\Throwable::class);
        Expect::that(new Retry(1, AbstractRetryFailure::class)->onlyOn)
            ->because('an abstract throwable is a valid retry type filter')
            ->toBe(AbstractRetryFailure::class);
    }

    #[Test]
    #[DataSet('invalidThrowableTypes')]
    public function invalidThrowableTypesAreRejected(string $onlyOn): void
    {
        Expect::that(
            static fn(): object => new \ReflectionClass(Retry::class)->newInstance(1, $onlyOn),
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

abstract class AbstractRetryFailure extends \Exception {}
