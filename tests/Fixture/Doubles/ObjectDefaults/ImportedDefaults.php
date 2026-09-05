<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

use Greenlight\Tests\Fixture\Doubles\ObjectDefaults\{Choice as SelectedChoice, Value as DefaultValue};
use const Greenlight\Tests\Fixture\Doubles\ObjectDefaults\{DEFAULT_LABEL as Label, DEFAULT_LIMIT as Limit};

const DEFAULT_LABEL = 'imported';
const DEFAULT_LIMIT = 7;

interface ImportedDefaults
{
    public function run(
        DefaultValue $value = new DefaultValue(
            payload: ['limit' => Limit, 'choice' => SelectedChoice::First, 'class' => DefaultValue::class],
            label: Label,
        ),
        string $marker = '',
    ): void;

    /** @param array<array-key, mixed> $values */
    public function nested(
        array $values = [
            'objects' => [new DefaultValue("line\nnull\0slash\\quote'", ['nested' => new DefaultValue(Label)])],
            'choice' => SelectedChoice::First,
            'choiceValue' => SelectedChoice::First->value,
            'choiceName' => SelectedChoice::First->name,
            'integer' => Limit + 1,
            'float' => 1.2345678901234567,
            'text' => 'new Alias() /* text */ $value, ) ]',
        ],
        string $marker = '',
    ): void;
}
