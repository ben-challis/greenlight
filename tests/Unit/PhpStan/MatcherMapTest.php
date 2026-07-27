<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\PhpStan\MatcherMapError;
use Greenlight\Tests\Fixture\PhpStan\MatcherTypeShapes;

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
        $subjectType = $map->subjectParameter('toHaveDigestLength')?->getType();

        Expect::that($map->has('toBeHexadecimal'))->because('collects matchers with subject stripped')->toBeTrue()
            ->and($map->has('toHaveDigestLength'))->toBeTrue()
            ->and($map->has('toBeSomethingElse'))->toBeFalse()
            ->and($map->parameters('toBeHexadecimal'))->toBe([])
            ->and(\count($lengthParameters))->toBe(1)
            ->and($lengthParameters[0]->getName())->toBe('length')
            ->and($lengthType instanceof \ReflectionNamedType ? $lengthType->getName() : null)->toBe('int')
            ->and($subjectType instanceof \ReflectionNamedType ? $subjectType->getName() : null)->toBe('string');
    }

    #[Test]
    public function identicalDeclarationsAcrossFilesUnionSilently(): void
    {
        $map = MatcherMap::fromConfigFiles([self::CONFIG, self::CONFIG]);

        Expect::that($map->has('toHaveDigestLength'))->because('identical declarations from multiple files merge without an error')->toBeTrue();
    }

    #[Test]
    public function conflictingSignaturesAreRefused(): void
    {
        Expect::that(
            static fn(): MatcherMap => MatcherMap::fromConfigFiles([self::CONFIG, self::CONFLICTING_CONFIG]),
        )->because('conflicting signatures are refused')->toThrow(MatcherMapError::class);
    }

    #[Test]
    public function anUnknownMatcherNameFailsLoudly(): void
    {
        $map = MatcherMap::fromConfigFiles([]);

        Expect::that(static fn(): array => $map->parameters('toBeMissing'))
            ->because('an unknown matcher name fails loudly')
            ->toThrow(
                \LogicException::class,
                message: 'No extension matcher named "toBeMissing" is known.',
            );
    }

    #[Test]
    #[DataSet('matcherTypes')]
    public function matcherTypesRenderForGeneratedSignatures(string $method, string $expected): void
    {
        $parameter = new \ReflectionMethod(MatcherTypeShapes::class, $method)->getParameters()[0];

        Expect::that(MatcherMap::typeName($parameter->getType()))
            ->because('matcher types render for generated signatures')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function matcherTypes(): iterable
    {
        yield 'untyped' => ['untyped', 'mixed'];
        yield 'union' => ['union', 'string|int'];
        yield 'intersection' => ['intersection', 'Countable&Iterator'];
    }
}
