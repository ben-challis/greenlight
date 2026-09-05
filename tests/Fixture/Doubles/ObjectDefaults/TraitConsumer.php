<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

class TraitConsumer
{
    use TraitDefaults {
        fromTrait as aliasDefault;
    }

    private const string PRIVATE_LABEL = 'trait consumer';
}
