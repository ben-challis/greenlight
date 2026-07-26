<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\ExternalDataSets;

final class SharedDataSets
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function words(): iterable
    {
        yield 'greeting' => ['hello'];
        yield 'farewell' => ['goodbye'];
    }
}
