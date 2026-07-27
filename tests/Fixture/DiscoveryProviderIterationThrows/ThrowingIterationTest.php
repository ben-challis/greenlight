<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryProviderIterationThrows;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class ThrowingIterationTest
{
    #[Test]
    #[DataSet('rows')]
    public function receivesAValue(string $value): void
    {
        echo $value;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rows(): iterable
    {
        yield 'first' => ['value'];

        throw new \RuntimeException('iteration exploded');
    }
}
