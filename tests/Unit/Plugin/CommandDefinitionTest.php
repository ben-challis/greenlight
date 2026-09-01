<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Command\ExitCode;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\CommandDefinition;
use Greenlight\Plugin\CommandInvocation;

final readonly class CommandDefinitionTest
{
    #[Test]
    #[DataSet('invalidDefinitions')]
    public function invalidDefinitionsAreRejected(string $name, string $description, string $message): void
    {
        Expect::that(static fn(): CommandDefinition => new CommandDefinition(
            $name,
            $description,
            static fn(CommandInvocation $invocation): ExitCode => ExitCode::success(),
        ))->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /** @return iterable<string, array{string, string, non-empty-string}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'empty name' => [
            '',
            'Description',
            'Command names MUST start with a lowercase ASCII letter. They MUST contain only lowercase ASCII letters, digits, hyphens, or colons.',
        ];
        yield 'uppercase name' => [
            'Company:hello',
            'Description',
            'Command names MUST start with a lowercase ASCII letter. They MUST contain only lowercase ASCII letters, digits, hyphens, or colons.',
        ];
        yield 'empty description' => [
            'company:hello',
            '',
            'Command descriptions MUST be non-empty single-line strings.',
        ];
        yield 'multiline description' => [
            'company:hello',
            "First line\nsecond line",
            'Command descriptions MUST be non-empty single-line strings.',
        ];
    }
}
