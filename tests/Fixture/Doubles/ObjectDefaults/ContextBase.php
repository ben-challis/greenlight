<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

class ContextBase
{
    protected const string PARENT_LABEL = 'parent';

    private const string PRIVATE_LABEL = 'private parent';

    public function inherited(
        Value $value = new Value(self::PRIVATE_LABEL, ['class' => self::class, 'method' => __METHOD__]), // @phpstan-ignore magicConstant.outOfFunction (PHP resolves the method name in parameter defaults.)
        string $marker = '',
    ): void {}
}
