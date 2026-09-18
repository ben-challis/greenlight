<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Input;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Input\ParsedArguments;

use function Greenlight\expect;

final class ParsedArgumentsTest
{
    #[Test]
    public function valueReturnsTheLastRecordedOptionValue(): void
    {
        $arguments = new ParsedArguments('run', [
            'group' => ['first', 'last'],
        ]);

        expect($arguments->value('group'))
            ->because('a singular lookup uses the last repeated option value')
            ->toBe('last');
    }

    #[Test]
    public function valuesRemoveMissingValuesWithoutChangingOrder(): void
    {
        $arguments = new ParsedArguments(null, [
            'option' => ['first', null, 'last'],
            'flag' => [null],
        ]);

        expect($arguments->values('option'))
            ->because('repeatable values retain input order and omit absent values')
            ->toBe(['first', 'last']);
        expect($arguments->has('flag'))
            ->because('a flag with no value is still present')
            ->toBeTrue();
        expect($arguments->value('flag'))
            ->toBeNull();
        expect($arguments->has('missing'))
            ->toBeFalse();
    }

    #[Test]
    public function valuesPreserveFalseyStrings(): void
    {
        $arguments = new ParsedArguments(null, [
            'option' => ['', null, '0'],
        ]);

        expect($arguments->values('option'))
            ->because('repeatable option values MUST remove only absent null entries')
            ->toBe(['', '0']);
        expect($arguments->value('option'))
            ->because('a singular option lookup MUST preserve a final zero string')
            ->toBe('0');
    }
}
