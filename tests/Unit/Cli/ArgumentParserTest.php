<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\ArgumentParser;
use Greenlight\Cli\CliError;
use Greenlight\Cli\OptionSpec;
use Greenlight\Cli\OptionValue;
use Greenlight\Tests\Support\Check;

final class ArgumentParserTest
{
    #[Test]
    public function parsesCommandOptionsAndRepeatedValues(): void
    {
        $parsed = self::parser()->parse([
            'run',
            '--workers=4',
            '--bail',
            '--group=slow',
            '--group=io',
            '--seed=123',
        ]);

        Check::same('run', $parsed->command, 'command');
        Check::same('4', $parsed->value('workers'), 'workers value');
        Check::true($parsed->has('bail'), 'bail to be present');
        Check::same(null, $parsed->value('bail'), 'bail given without a value');
        Check::same(['slow', 'io'], $parsed->values('group'), 'group values in order');
        Check::same('123', $parsed->value('seed'), 'seed value');
    }

    #[Test]
    public function optionalValueOptionsAcceptAValue(): void
    {
        $parsed = self::parser()->parse(['--bail=3']);

        Check::same(null, $parsed->command, 'no command');
        Check::same('3', $parsed->value('bail'), 'bail value');
    }

    #[Test]
    public function shortAliasesMapToTheirLongOptions(): void
    {
        Check::true(self::parser()->parse(['-h'])->has('help'), '-h to mean --help');
        Check::true(self::parser()->parse(['-V'])->has('version'), '-V to mean --version');
    }

    #[Test]
    public function rejectsMalformedInput(): void
    {
        $malformed = [
            'unknown long option' => ['--nope'],
            'unknown short option' => ['-x'],
            'value on a flag' => ['--help=yes'],
            'missing required value' => ['--workers'],
            'repeated non-repeatable option' => ['--workers=1', '--workers=2'],
            'second positional argument' => ['run', 'again'],
            'bare double dash' => ['--'],
        ];

        foreach ($malformed as $what => $argv) {
            Check::throws(
                static function () use ($argv): void {
                    self::parser()->parse($argv);
                },
                CliError::class,
                $what,
            );
        }
    }

    private static function parser(): ArgumentParser
    {
        return new ArgumentParser([
            new OptionSpec('workers', OptionValue::Required),
            new OptionSpec('bail', OptionValue::Optional),
            new OptionSpec('group', OptionValue::Required, repeatable: true),
            new OptionSpec('seed', OptionValue::Required),
            new OptionSpec('help', short: 'h'),
            new OptionSpec('version', short: 'V'),
        ]);
    }
}
