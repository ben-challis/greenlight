<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Test\TestChannel;

use function Greenlight\expect;

final readonly class TestChannelTest
{
    #[Test]
    public function exposesTheSlotNumberAndAPrefixedLabel(): void
    {
        $channel = new TestChannel(3);

        expect($channel->number)->because('exposes the slot number and a prefixed label')->toBe(3);
        expect($channel->label())->toBe('gl-3');
    }

    #[Test]
    #[DataSet('nonPositiveNumbers')]
    public function rejectsNonPositiveNumbers(int $number): void
    {
        expect()->calling(static fn(): TestChannel => new TestChannel($number))
            ->because('a test channel MUST identify a positive worker slot')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use a test channel number greater than zero.',
            );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositiveNumbers(): iterable
    {
        yield 'zero' => [0];

        yield 'negative' => [-1];
    }
}
