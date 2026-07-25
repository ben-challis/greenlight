<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DataRows;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Fail;

final class InlineRowsTest
{
    #[Test]
    #[DataRow([1, 2, 3], label: 'small')]
    #[DataRow([10, 20, 30])]
    public function addsUp(int $a, int $b, int $sum): void
    {
        if ($a + $b !== $sum) {
            Fail::because(\sprintf(
                'Expected %d + %d to equal %d, got %d.',
                $a,
                $b,
                $sum,
                $a + $b,
            ));
        }
    }

    #[Test]
    #[DataRow(['inline'], label: 'from attribute')]
    #[DataSet('providedWords')]
    public function acceptsWord(string $word): void
    {
        if ($word === '') {
            Fail::because('Expected the data-row word to be non-empty.');
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
