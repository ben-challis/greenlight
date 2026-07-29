<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Expect\Expect;

final class ThrowableDetailWireBoundsTest
{
    #[Test]
    #[DataSet('nonPositiveLines')]
    public function wireInputNormalizesNonPositiveLines(int $line): void
    {
        $restored = ThrowableDetail::fromWire([
            'class' => \RuntimeException::class,
            'message' => 'Failure.',
            'file' => '/tests/ExampleTest.php',
            'line' => $line,
            'stackFrames' => [],
        ]);

        Expect::that($restored->line)
            ->because('wire throwable details MUST identify at least the first source line')
            ->toBe(1);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositiveLines(): iterable
    {
        yield 'zero line' => [0];
        yield 'negative line' => [-1];
    }
}
