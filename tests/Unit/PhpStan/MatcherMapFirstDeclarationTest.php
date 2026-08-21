<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\PhpStan\MatcherMapError;

final class MatcherMapFirstDeclarationTest
{
    private const string CONFIG = __DIR__ . '/../../Fixture/PhpStanExtension/greenlight.php';
    private const string CONFIG_ALIAS = __DIR__ . '/../../Fixture/PhpStanExtension/../PhpStanExtension/greenlight.php';
    private const string CONFLICTING_CONFIG = __DIR__ . '/../../Fixture/PhpStanExtensionConflict/greenlight.php';

    #[Test]
    public function anIdenticalRedeclarationDoesNotReplaceTheFirstDeclarationPath(): void
    {
        Expect::that(
            static fn(): MatcherMap => MatcherMap::fromConfigFiles([
                self::CONFIG,
                self::CONFIG_ALIAS,
                self::CONFLICTING_CONFIG,
            ]),
        )->because('a later conflict identifies the first matcher declaration')
            ->toThrow(
                MatcherMapError::class,
                message: \sprintf(
                    'Extension matcher "toHaveDigestLength" is declared with conflicting signatures: '
                    . '(string $subject, int $length): bool in "%s" and '
                    . '(mixed $subject, string $length): bool in "%s". '
                    . 'Static analysis needs one signature per matcher name across all configured files.',
                    self::CONFIG,
                    self::CONFLICTING_CONFIG,
                ),
            );
    }
}
