<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Attribute;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Expect\Expect;

final class TimeoutValidationTest
{
    #[Test]
    #[DataSet('invalidSeconds')]
    public function invalidSecondsHaveAnActionableDiagnostic(float $seconds): void
    {
        Expect::that(static fn(): Timeout => new Timeout($seconds))
            ->because('invalid timeout seconds MUST explain the accepted range')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Timeout seconds must be finite and greater than zero.',
            );
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidSeconds(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.5];
        yield 'not a number' => [\NAN];
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
    }
}
