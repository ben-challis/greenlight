<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\PhpStan\MatcherMapError;

final class MatcherMapTest
{
    private const string CONFIG = __DIR__ . '/../../Fixture/PhpStanExtension/greenlight.php';
    private const string CONFLICTING_CONFIG = __DIR__ . '/../../Fixture/PhpStanExtensionConflict/greenlight.php';

    #[Test]
    public function collectsMatchersWithSubjectStripped(): void
    {
        $map = MatcherMap::fromConfigFiles([self::CONFIG]);

        $lengthParameters = $map->parameters('toHaveDigestLength');
        $lengthType = $lengthParameters[0]->getType();

        Expect::that($map->has('toBeHexadecimal'))->toBeTrue()
            ->and($map->has('toHaveDigestLength'))->toBeTrue()
            ->and($map->has('toBeSomethingElse'))->toBeFalse()
            ->and($map->parameters('toBeHexadecimal'))->toBe([])
            ->and(\count($lengthParameters))->toBe(1)
            ->and($lengthParameters[0]->getName())->toBe('length')
            ->and($lengthType instanceof \ReflectionNamedType ? $lengthType->getName() : null)->toBe('int');
    }

    #[Test]
    public function identicalDeclarationsAcrossFilesUnionSilently(): void
    {
        $map = MatcherMap::fromConfigFiles([self::CONFIG, self::CONFIG]);

        Expect::that($map->has('toHaveDigestLength'))->toBeTrue();
    }

    #[Test]
    public function conflictingSignaturesAreRefused(): void
    {
        Expect::that(
            static fn(): MatcherMap => MatcherMap::fromConfigFiles([self::CONFIG, self::CONFLICTING_CONFIG]),
        )->toThrow(MatcherMapError::class);
    }
}
