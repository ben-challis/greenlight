<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Input;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Input\ArgumentParser;
use Greenlight\Cli\Input\OptionSpec;
use Greenlight\Expect\Expect;

final readonly class ArgumentParserDefinitionTest
{
    /**
     * @param list<OptionSpec> $specs
     */
    #[Test]
    #[DataSet('conflictingDefinitions')]
    public function conflictingOptionDefinitionsAreRejected(array $specs, string $message): void
    {
        Expect::that(static fn(): ArgumentParser => new ArgumentParser($specs))
            ->because('option maps MUST NOT silently replace conflicting definitions')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{list<OptionSpec>, string}>
     */
    public static function conflictingDefinitions(): iterable
    {
        yield 'duplicate long name' => [
            [
                new OptionSpec('help', short: 'h'),
                new OptionSpec('help', short: 'H'),
            ],
            'Option "--help" is defined more than once.',
        ];
        yield 'duplicate short alias' => [
            [
                new OptionSpec('help', short: 'h'),
                new OptionSpec('host', short: 'h'),
            ],
            'Short option "-h" is assigned to more than one option.',
        ];
    }
}
