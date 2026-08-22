<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\ResultPolicy;

final class ResultPolicyValidationTest
{
    /**
     * @param array<mixed> $ignoreDeprecations
     */
    #[Test]
    #[DataSet('invalidPatterns')]
    public function invalidIgnorePatternsAreRejected(array $ignoreDeprecations): void
    {
        Expect::that(static fn(): ResultPolicy => new ResultPolicy(
            ignoreDeprecations: $ignoreDeprecations,
        ))
            ->because('deprecation ignore patterns MUST be a list of non-empty strings')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Deprecation ignore patterns MUST be a list of non-empty strings.',
            );
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidPatterns(): iterable
    {
        yield 'associative patterns' => [['legacy' => 'legacy']];
        yield 'empty pattern' => [['']];
        yield 'wrong item type' => [[42]];
    }
}
