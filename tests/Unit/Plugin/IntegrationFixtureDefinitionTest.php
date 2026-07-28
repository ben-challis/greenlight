<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\IntegrationFixtureDefinition;

final readonly class IntegrationFixtureDefinitionTest
{
    /**
     * @param list<string> $dependencies
     */
    #[Test]
    #[DataSet('invalidDefinitions')]
    public function invalidDefinitionsAreRejected(
        string $id,
        array $dependencies,
        string $message,
    ): void {
        Expect::that(static fn(): IntegrationFixtureDefinition => new IntegrationFixtureDefinition(
            $id,
            static function (): void {},
            $dependencies,
        ))
            ->because('integration fixture definitions MUST reject invalid identifiers')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'empty fixture ID' => [
            '',
            [],
            'Integration fixture IDs must be non-empty UTF-8 strings.',
        ];
        yield 'non-UTF-8 fixture ID' => [
            "\xB1\x31",
            [],
            'Integration fixture IDs must be non-empty UTF-8 strings.',
        ];
        yield 'empty dependency ID' => [
            'database',
            [''],
            'Integration fixture "database" has an invalid dependency ID.',
        ];
        yield 'non-UTF-8 dependency ID' => [
            'database',
            ["\xB1\x31"],
            'Integration fixture "database" has an invalid dependency ID.',
        ];
        yield 'duplicate dependency' => [
            'database',
            ['network', 'network'],
            'Integration fixture "database" declares dependency "network" more than once.',
        ];
    }
}
