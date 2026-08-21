<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\PhpStan\MatcherMapError;

final class MatcherMapReturnConflictTest
{
    private const string BOOLEAN_CONFIG = __DIR__ . '/../../Fixture/PhpStanMatcherReturnConflict/boolean.php';
    private const string EQUIVALENT_CONFIG = __DIR__ . '/../../Fixture/PhpStanMatcherReturnConflict/equivalent.php';
    private const string LITERAL_CONFIG = __DIR__ . '/../../Fixture/PhpStanMatcherReturnConflict/literal.php';

    #[Test]
    #[DataRow([self::BOOLEAN_CONFIG, self::LITERAL_CONFIG, 'bool', 'true'], label: 'bool then true')]
    #[DataRow([self::LITERAL_CONFIG, self::BOOLEAN_CONFIG, 'true', 'bool'], label: 'true then bool')]
    public function returnTypesConflictInEitherConfigurationOrder(
        string $firstConfig,
        string $secondConfig,
        string $firstReturn,
        string $secondReturn,
    ): void {
        Expect::that(
            static fn(): MatcherMap => MatcherMap::fromConfigFiles([$firstConfig, $secondConfig]),
        )
            ->because('return types are part of the normalized matcher signature')
            ->toThrow(
                MatcherMapError::class,
                message: \sprintf(
                    'Extension matcher "toBeAvailable" is declared with conflicting signatures: '
                    . '(string $subject): %s in "%s" and (string $subject): %s in "%s". '
                    . 'Static analysis needs one signature per matcher name across all configured files.',
                    $firstReturn,
                    $firstConfig,
                    $secondReturn,
                    $secondConfig,
                ),
            );
    }

    #[Test]
    #[DataRow([self::BOOLEAN_CONFIG, self::EQUIVALENT_CONFIG], label: 'first then equivalent')]
    #[DataRow([self::EQUIVALENT_CONFIG, self::BOOLEAN_CONFIG], label: 'equivalent then first')]
    public function equivalentReturnTypesMergeInEitherConfigurationOrder(
        string $firstConfig,
        string $secondConfig,
    ): void {
        $map = MatcherMap::fromConfigFiles([$firstConfig, $secondConfig]);

        Expect::that($map->has('toBeAvailable'))
            ->because('equivalent normalized matcher signatures do not conflict')
            ->toBeTrue();
    }
}
