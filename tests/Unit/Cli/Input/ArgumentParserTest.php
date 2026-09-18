<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Input;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Input\ArgumentParser;
use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\OptionSpec;
use Greenlight\Cli\Input\OptionValue;

use function Greenlight\expect;

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

        expect($parsed->command)->because('parses command options and repeated values')->toBe('run');
        expect($parsed->value('workers'))->because('parses command options and repeated values')->toBe('4');
        expect($parsed->has('bail'))->because('parses command options and repeated values')->toBeTrue();
        expect($parsed->value('bail'))->because('parses command options and repeated values')->toBe(null);
        expect($parsed->values('group'))->because('parses command options and repeated values')->toBe(['slow', 'io']);
        expect($parsed->value('seed'))->because('parses command options and repeated values')->toBe('123');
    }

    #[Test]
    public function optionalValueOptionsAcceptAValue(): void
    {
        $parsed = self::parser()->parse(['--bail=3']);

        expect($parsed->command)->because('optional value options accept a value')->toBe(null);
        expect($parsed->value('bail'))->because('optional value options accept a value')->toBe('3');
    }

    #[Test]
    public function optionsCanSurroundTheCommandWithoutChangingTheirMeaning(): void
    {
        $parsed = self::parser()->parse([
            '--workers=4',
            'run',
            '--group=slow',
            '--seed=123',
        ]);

        expect($parsed->command)
            ->because('option position MUST NOT change the selected command')
            ->toBe('run');
        expect($parsed->value('workers'))
            ->toBe('4');
        expect($parsed->values('group'))
            ->toBe(['slow']);
        expect($parsed->value('seed'))
            ->toBe('123');
    }

    #[Test]
    public function shortAliasesMapToTheirLongOptions(): void
    {
        expect(self::parser()->parse(['-h'])->has('help'))->because('short aliases map to their long options')->toBeTrue();
        expect(self::parser()->parse(['-V'])->has('version'))->because('short aliases map to their long options')->toBeTrue();
    }

    /**
     * @param list<string> $argv
     */
    #[Test]
    #[DataSet('malformedArguments')]
    public function rejectsMalformedInputWithExactGuidance(array $argv, string $message): void
    {
        expect()->calling(
            static function () use ($argv): void {
                self::parser()->parse($argv);
            },
        )->toThrow(CliError::class, message: $message);
    }

    /**
     * @return iterable<string, array{list<string>, non-empty-string}>
     */
    public static function malformedArguments(): iterable
    {
        yield 'unknown long option' => [
            ['--nope'],
            'Unknown option "--nope". Use greenlight --help to list options.',
        ];
        yield 'unknown short option' => [
            ['-x'],
            'Unknown option "-x". Use greenlight --help to list options.',
        ];
        yield 'value on a flag' => [
            ['--help=yes'],
            'Option --help does not take a value.',
        ];
        yield 'missing required value' => [
            ['--workers'],
            'Option --workers requires a value. Use --workers=<value>.',
        ];
        yield 'repeated non-repeatable option' => [
            ['--workers=1', '--workers=2'],
            'Specify option --workers only once.',
        ];
        yield 'second positional argument' => [
            ['run', 'again'],
            'Unexpected argument "again".',
        ];
        yield 'bare double dash' => [
            ['--'],
            '"--" requires an option name.',
        ];
        yield 'missing long option name' => [
            ['--=value'],
            'Unknown option "--". Use greenlight --help to list options.',
        ];
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
