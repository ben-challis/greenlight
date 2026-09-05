<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

class ContextDefaults extends ContextBase
{
    protected const string PARENT_LABEL = 'child';

    private const string PRIVATE_LABEL = 'private child';

    public function run(
        Value $value = new Value(
            self::PRIVATE_LABEL,
            [
                'parent' => parent::PARENT_LABEL,
                'qualified' => ContextDefaults::PRIVATE_LABEL,
                'class' => self::class,
                'parentClass' => parent::class,
            ],
        ),
        string $marker = '',
    ): void {}
}
