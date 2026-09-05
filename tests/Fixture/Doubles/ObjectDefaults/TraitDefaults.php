<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

use Greenlight\Tests\Fixture\Doubles\ObjectDefaults\Value as TraitValue;

trait TraitDefaults
{
    public function fromTrait(
        TraitValue $value = new TraitValue(
            self::PRIVATE_LABEL,
            [
                'file' => __FILE__,
                'directory' => __DIR__,
                'line' => __LINE__,
                'namespace' => __NAMESPACE__,
                'class' => __CLASS__,
                'trait' => __TRAIT__,
                'method' => __METHOD__, // @phpstan-ignore magicConstant.outOfFunction (PHP resolves the method name in parameter defaults.)
                'function' => __FUNCTION__, // @phpstan-ignore magicConstant.outOfFunction (PHP resolves the function name in parameter defaults.)
            ],
        ),
        string $marker = '',
    ): void {}
}
