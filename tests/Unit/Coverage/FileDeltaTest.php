<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\Diff\FileDelta;
use Greenlight\Expect\Expect;

final readonly class FileDeltaTest
{
    #[Test]
    public function zeroStringFilePathRetainsDeltaDetails(): void
    {
        $delta = new FileDelta('0', 75.0, 50.0, [2, 5]);

        Expect::that([$delta->file, $delta->newlyUncoveredLines, $delta->delta()])
            ->because('a zero-string coverage delta file path is not empty')
            ->toBe(['0', [2, 5], -25.0]);
    }

    /**
     * @param list<int> $newlyUncoveredLines
     */
    #[Test]
    #[DataSet('invalidDeltas')]
    public function invalidDeltaIdentitiesAreRejected(
        string $file,
        array $newlyUncoveredLines,
        string $message,
    ): void {
        Expect::that(static fn(): FileDelta => new FileDelta(
            $file,
            100.0,
            50.0,
            $newlyUncoveredLines,
        ))
            ->because('coverage deltas MUST identify a file and positive source lines')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, list<int>, string}>
     */
    public static function invalidDeltas(): iterable
    {
        yield 'empty file' => [
            '',
            [1],
            'Use a non-empty coverage delta file path.',
        ];
        yield 'zero line' => [
            '/src/A.php',
            [0],
            'Use positive newly uncovered line numbers. Actual value: 0.',
        ];
        yield 'negative line' => [
            '/src/A.php',
            [-1],
            'Use positive newly uncovered line numbers. Actual value: -1.',
        ];
    }
}
