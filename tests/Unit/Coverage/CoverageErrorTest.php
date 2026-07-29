<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageError;
use Greenlight\Expect\Expect;

final readonly class CoverageErrorTest
{
    #[Test]
    #[DataSet('sharedDirectoryCreationFailures')]
    public function sharedDirectoryCreationFailuresRetainAvailableCause(
        ?string $cause,
        string $expected,
    ): void {
        $error = CoverageError::sharedDirectoryCreationFailed('/tmp/coverage', $cause);

        Expect::that($error->getMessage())
            ->because('shared coverage creation diagnostics MUST retain each available cause')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{?string, non-empty-string}>
     */
    public static function sharedDirectoryCreationFailures(): iterable
    {
        yield 'no cause' => [
            null,
            'Failed to create shared coverage directory "/tmp/coverage".',
        ];
        yield 'zero cause' => [
            '0',
            'Failed to create shared coverage directory "/tmp/coverage": 0.',
        ];
    }
}
