<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryDataSets;

final class InvalidKeyProvider
{
    /**
     * @return iterable<mixed, array{string}>
     */
    public static function rows(): iterable
    {
        yield true => ['value'];
    }
}
