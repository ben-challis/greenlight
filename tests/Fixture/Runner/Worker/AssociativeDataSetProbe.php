<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Worker;

final class AssociativeDataSetProbe
{
    public function accepts(string $currency, int $minorUnits): void {}

    /**
     * @return iterable<string, array{currency: string, minorUnits: int}>
     */
    public static function associativeRows(): iterable
    {
        yield 'sterling' => [
            'currency' => 'GBP',
            'minorUnits' => 100,
        ];
    }
}
