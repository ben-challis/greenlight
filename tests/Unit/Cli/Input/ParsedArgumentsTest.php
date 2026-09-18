<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Input;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Expect\Expect;

final class ParsedArgumentsTest
{
    #[Test]
    public function valueReturnsTheLastRecordedOptionValue(): void
    {
        $arguments = new ParsedArguments('run', [
            'group' => ['first', 'last'],
        ]);

        Expect::value($arguments->value('group'))
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

        Expect::value($arguments->values('option'))
            ->because('repeatable values retain input order and omit absent values')
            ->toBe(['first', 'last']);
        Expect::value($arguments->has('flag'))
            ->because('a flag with no value is still present')
            ->toBeTrue();
        Expect::value($arguments->value('flag'))
            ->toBeNull();
        Expect::value($arguments->has('missing'))
            ->toBeFalse();
    }

    #[Test]
    public function valuesPreserveFalseyStrings(): void
    {
        $arguments = new ParsedArguments(null, [
            'option' => ['', null, '0'],
        ]);

        Expect::value($arguments->values('option'))
            ->because('repeatable option values MUST remove only absent null entries')
            ->toBe(['', '0']);
        Expect::value($arguments->value('option'))
            ->because('a singular option lookup MUST preserve a final zero string')
            ->toBe('0');
    }
}
