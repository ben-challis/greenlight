<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryProviderSlowCompletion;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class SlowCompletionTest
{
    #[Test]
    #[DataSet('finishesSlowly')]
    public function receivesAValue(string $value): void
    {
        echo $value;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function finishesSlowly(): iterable
    {
        yield 'first' => ['value'];

        \usleep(20_000);
    }
}
