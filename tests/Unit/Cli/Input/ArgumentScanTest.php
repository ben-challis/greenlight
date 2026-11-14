<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Input;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Input\Definition;

use function Greenlight\expect;

final class ArgumentScanTest
{
    #[Test]
    public function scanFindsTheCommandAndKnownOptionsAndSkipsUnknownOptions(): void
    {
        $scan = new Definition()->parser()->scan([
            '--plugin-flag',
            '--config=first.php',
            'custom:command',
            'extra',
            '--no-ansi',
            '--config=last.php',
        ]);

        expect($scan->command)->toBe('custom:command');
        expect($scan->commandIndex)->toBe(2);
        expect($scan->has('no-ansi'))
            ->because('the scan reads known options after the command word')
            ->toBeTrue();
        expect($scan->value('config'))
            ->because('the last configuration option wins')
            ->toBe('last.php');
        expect($scan->has('plugin-flag'))
            ->because('the scan skips options that a plugin command can define')
            ->toBeFalse();
        expect($scan->valueOptionBeforeCommand)->toBeNull();
    }

    #[Test]
    public function scanReportsARequiredValueOptionDirectlyBeforeTheCommandWord(): void
    {
        expect(new Definition()->parser()->scan(['--config', 'greenlight.php'])->valueOptionBeforeCommand)
            ->toBe('config');
        expect(new Definition()->parser()->scan(['--bail', 'run'])->valueOptionBeforeCommand)
            ->because('an optional value uses only the equals form, so the next word is a command')
            ->toBeNull();
    }
}
