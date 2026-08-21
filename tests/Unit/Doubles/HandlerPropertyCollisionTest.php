<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\PrivateHandlerProperty;
use Greenlight\Tests\Fixture\Doubles\ProtectedHandlerPropertyCollision;
use Greenlight\Tests\Fixture\Doubles\PublicHandlerPropertyCollision;
use Greenlight\Tests\Fixture\Doubles\PublicStaticHandlerPropertyCollision;

final readonly class HandlerPropertyCollisionTest
{
    public function __construct(private Doubles $doubles) {}

    /**
     * @param class-string $type
     */
    #[Test]
    #[DataSet('visibleHandlerProperties')]
    public function visibleHandlerPropertiesCannotBeDoubled(string $type): void
    {
        Expect::that(fn(): object => $this->doubles->stub($type))
            ->because('a visible property conflicts with proxy handler storage')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: $type . ' declares $__greenlightHandler. '
                    . 'This property conflicts with the proxy handler storage property.',
            );
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function visibleHandlerProperties(): iterable
    {
        yield 'public property' => [PublicHandlerPropertyCollision::class];
        yield 'protected property' => [ProtectedHandlerPropertyCollision::class];
        yield 'public static property' => [PublicStaticHandlerPropertyCollision::class];
    }

    #[Test]
    public function privateHandlerPropertiesRemainValid(): void
    {
        Expect::that($this->doubles->stub(PrivateHandlerProperty::class))
            ->because('a private parent property does not conflict with proxy handler storage')
            ->toBeInstanceOf(PrivateHandlerProperty::class);
    }
}
