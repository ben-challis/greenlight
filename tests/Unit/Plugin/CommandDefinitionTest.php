<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\CommandDefinition;
use Greenlight\Plugin\CommandInvocation;
use Greenlight\Plugin\CommandResult;

final readonly class CommandDefinitionTest
{
    #[Test]
    #[DataSet('invalidDefinitions')]
    public function invalidDefinitionsAreRejected(string $name, string $description, string $message): void
    {
        Expect::that(static fn(): CommandDefinition => new CommandDefinition(
            $name,
            $description,
            static fn(CommandInvocation $invocation): CommandResult => CommandResult::success(),
        ))->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /** @return iterable<string, array{string, string, non-empty-string}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'empty name' => [
            '',
            'Description',
            'Start command names with a lowercase ASCII letter. Use lowercase ASCII letters and digits, with single hyphens or colons between segments.',
        ];
        yield 'uppercase name' => [
            'Company:hello',
            'Description',
            'Start command names with a lowercase ASCII letter. Use lowercase ASCII letters and digits, with single hyphens or colons between segments.',
        ];
        yield 'empty description' => [
            'company:hello',
            '',
            'Use a non-empty single-line string for each command description.',
        ];
        yield 'repeated separator' => [
            'company::hello',
            'Description',
            'Start command names with a lowercase ASCII letter. Use lowercase ASCII letters and digits, with single hyphens or colons between segments.',
        ];
        yield 'trailing separator' => [
            'company:',
            'Description',
            'Start command names with a lowercase ASCII letter. Use lowercase ASCII letters and digits, with single hyphens or colons between segments.',
        ];
        yield 'multiline description' => [
            'company:hello',
            "First line\nsecond line",
            'Use a non-empty single-line string for each command description.',
        ];
    }
}
