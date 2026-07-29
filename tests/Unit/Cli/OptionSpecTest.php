<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\ArgumentParser;
use Greenlight\Cli\OptionSpec;
use Greenlight\Expect\Expect;

final readonly class OptionSpecTest
{
    #[Test]
    public function zeroStringLongNameParsesAsAFlag(): void
    {
        $arguments = new ArgumentParser([new OptionSpec('0')])->parse(['--0']);

        Expect::that($arguments->has('0'))
            ->because('a zero-string long option name is not empty')
            ->toBeTrue();
    }

    #[Test]
    #[DataSet('invalidDefinitions')]
    public function invalidOptionDefinitionsAreRejected(
        string $name,
        ?string $short,
        string $message,
    ): void {
        Expect::that(static fn(): OptionSpec => new OptionSpec($name, short: $short))
            ->because('the argument parser MUST receive unambiguous option definitions')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'empty long name' => [
            '',
            null,
            'Option names cannot be empty.',
        ];
        yield 'empty short alias' => [
            'help',
            '',
            'Short option alias "" MUST be one ASCII letter.',
        ];
        yield 'multi-letter short alias' => [
            'help',
            'help',
            'Short option alias "help" MUST be one ASCII letter.',
        ];
        yield 'numeric short alias' => [
            'workers',
            '1',
            'Short option alias "1" MUST be one ASCII letter.',
        ];
    }
}
