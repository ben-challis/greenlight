<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\ArgumentParser;
use Greenlight\Cli\CliError;
use Greenlight\Cli\OptionSpec;
use Greenlight\Cli\OptionValue;
use Greenlight\Expect\Expect;

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

        Expect::that($parsed->command)->toBe('run');
        Expect::that($parsed->value('workers'))->toBe('4');
        Expect::that($parsed->has('bail'))->toBeTrue();
        Expect::that($parsed->value('bail'))->toBe(null);
        Expect::that($parsed->values('group'))->toBe(['slow', 'io']);
        Expect::that($parsed->value('seed'))->toBe('123');
    }

    #[Test]
    public function optionalValueOptionsAcceptAValue(): void
    {
        $parsed = self::parser()->parse(['--bail=3']);

        Expect::that($parsed->command)->toBe(null);
        Expect::that($parsed->value('bail'))->toBe('3');
    }

    #[Test]
    public function shortAliasesMapToTheirLongOptions(): void
    {
        Expect::that(self::parser()->parse(['-h'])->has('help'))->toBeTrue();
        Expect::that(self::parser()->parse(['-V'])->has('version'))->toBeTrue();
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

        foreach ($malformed as $argv) {
            Expect::that(
                static function () use ($argv): void {
                    self::parser()->parse($argv);
                },
            )->toThrow(CliError::class);
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
