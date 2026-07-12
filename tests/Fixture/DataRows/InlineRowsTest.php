<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DataRows;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class InlineRowsTest
{
    #[Test]
    #[DataRow([1, 2, 3], label: 'small')]
    #[DataRow([10, 20, 30])]
    public function addsUp(int $a, int $b, int $sum): void
    {
        if ($a + $b !== $sum) {
            throw new \RuntimeException(\sprintf('%d + %d is not %d', $a, $b, $sum));
        }
    }

    #[Test]
    #[DataRow(['inline'], label: 'from attribute')]
    #[DataSet('providedWords')]
    public function acceptsWord(string $word): void
    {
        if ($word === '') {
            throw new \RuntimeException('empty word');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providedWords(): iterable
    {
        yield 'from provider' => ['provided'];
    }
}
