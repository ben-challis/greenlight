<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Tests\Fixture\Doubles\BackedEnumContract;
use Greenlight\Tests\Fixture\Doubles\BareTraversableContract;
use Greenlight\Tests\Fixture\Doubles\DateTimeContract;
use Greenlight\Tests\Fixture\Doubles\ThrowableContract;

use function Greenlight\expect;

final readonly class UnimplementableInterfaceTest
{
    public function __construct(private Doubles $doubles) {}

    /**
     * @param class-string $type
     */
    #[Test]
    #[DataRow([ThrowableContract::class, \Throwable::class], label: 'throwable')]
    #[DataRow([\Throwable::class, \Throwable::class], label: 'throwable itself')]
    #[DataRow([DateTimeContract::class, \DateTimeInterface::class], label: 'date time')]
    #[DataRow([BackedEnumContract::class, \UnitEnum::class], label: 'backed enum')]
    #[DataRow([\UnitEnum::class, \UnitEnum::class], label: 'unit enum itself')]
    public function internalOnlyInterfacesAreRejected(string $type, string $internal): void
    {
        expect()->calling(fn(): object => $this->doubles->stub($type))
            ->because('PHP refuses a user class that implements an internal-only interface')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: \sprintf(
                    '%s extends %s. Only PHP internal classes can implement %s, so Doubles cannot create a proxy. Use a different interface.',
                    $type,
                    $internal,
                    $internal,
                ),
            );
    }

    /**
     * @param class-string $type
     */
    #[Test]
    #[DataRow([BareTraversableContract::class], label: 'extended')]
    #[DataRow([\Traversable::class], label: 'itself')]
    public function bareTraversableInterfacesAreRejected(string $type): void
    {
        expect()->calling(fn(): object => $this->doubles->mock($type))
            ->because('PHP requires Iterator or IteratorAggregate for a Traversable class')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: \sprintf(
                    '%s extends Traversable, but not Iterator or IteratorAggregate. Doubles cannot create a proxy for it. '
                    . 'Make the interface extend Iterator or IteratorAggregate.',
                    $type,
                ),
            );
    }

    #[Test]
    public function iteratorInterfacesRemainDoubleable(): void
    {
        expect($this->doubles->stub(\IteratorAggregate::class))
            ->because('IteratorAggregate satisfies the Traversable requirement')
            ->toBeInstanceOf(\IteratorAggregate::class);
    }
}
