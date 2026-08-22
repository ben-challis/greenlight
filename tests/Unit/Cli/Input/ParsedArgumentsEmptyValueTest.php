<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Input;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Expect\Expect;

final class ParsedArgumentsEmptyValueTest
{
    #[Test]
    public function repeatableValuesPreserveEmptyStrings(): void
    {
        $arguments = new ParsedArguments(null, [
            'group' => ['first', '', null, 'last'],
        ]);

        Expect::that($arguments->values('group'))
            ->because('empty option values MUST remain available for downstream validation')
            ->toBe(['first', '', 'last']);
    }
}
