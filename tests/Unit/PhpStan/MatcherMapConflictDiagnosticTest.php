<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\PhpStan\MatcherMapError;

final class MatcherMapConflictDiagnosticTest
{
    private const string CONFIG = __DIR__ . '/../../Fixture/PhpStanExtension/greenlight.php';
    private const string CONFLICTING_CONFIG = __DIR__ . '/../../Fixture/PhpStanExtensionConflict/greenlight.php';

    #[Test]
    public function conflictingSignaturesIdentifyBothDeclarations(): void
    {
        Expect::that(
            static fn(): MatcherMap => MatcherMap::fromConfigFiles([
                self::CONFIG,
                self::CONFLICTING_CONFIG,
            ]),
        )
            ->because('a matcher conflict MUST identify both declarations')
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
