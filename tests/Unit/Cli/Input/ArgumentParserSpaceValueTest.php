<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Input;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Input\Definition;

use function Greenlight\expect;

final class ArgumentParserSpaceValueTest
{
    #[Test]
    public function aRequiredValueTakesTheNextWordEvenWhenItStartsWithAHyphen(): void
    {
        $arguments = new Definition()->parser()->parse(['--filter', '-legacy', 'run', '--workers', '2']);

        expect($arguments->command)->toBe('run');
        expect($arguments->value('filter'))
            ->because('the next word is the value, as with GNU getopt')
            ->toBe('-legacy');
        expect($arguments->value('workers'))->toBe('2');
    }
}
