<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\DataSets;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class DataSetsTest
{
    #[Test]
    #[DataSet('rows')]
    public function sumsToThree(int $a, int $b): void
    {
        if ($a + $b !== 3) {
            throw new \RuntimeException(\sprintf('%d + %d is not 3', $a, $b));
        }
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function rows(): iterable
    {
        yield 'one and two' => [1, 2];
        yield 'two and one' => [2, 1];
        yield 'broken row' => [2, 2];
    }
}
