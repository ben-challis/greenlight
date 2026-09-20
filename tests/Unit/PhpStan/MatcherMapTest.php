<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\PhpStan\MatcherMapError;
use Greenlight\Tests\Fixture\PhpStan\MatcherTypeShapes;

use function Greenlight\expect;

final class MatcherMapTest
{
    private const string CONFIG = __DIR__ . '/../../Fixture/PhpStanExtension/greenlight.php';
    private const string CONFLICTING_CONFIG = __DIR__ . '/../../Fixture/PhpStanExtensionConflict/greenlight.php';
    private const string NON_EXPECTATION_CONFIG = __DIR__ . '/../../Fixture/PluginRunConfig/greenlight.php';

    #[Test]
    public function collectsMatchersWithSubjectStripped(): void
    {
        $map = MatcherMap::fromConfigFiles([self::CONFIG]);

        $lengthParameters = $map->parameters('toHaveDigestLength');
        $lengthType = $lengthParameters[0]->getType();
        $subjectType = $map->subjectParameter('toHaveDigestLength')?->getType();

        expect($map->has('toBeHexadecimal'))->because('collects matchers with subject stripped')->toBeTrue();
        expect($map->has('toHaveDigestLength'))->toBeTrue();
        expect($map->has('toBeSomethingElse'))->toBeFalse();
        expect($map->parameters('toBeHexadecimal'))->toBe([]);
        expect(\count($lengthParameters))->toBe(1);
        expect($lengthParameters[0]->getName())->toBe('length');
        expect($lengthType instanceof \ReflectionNamedType ? $lengthType->getName() : null)->toBe('int');
        expect($subjectType instanceof \ReflectionNamedType ? $subjectType->getName() : null)->toBe('string');
    }

    #[Test]
    public function identicalDeclarationsAcrossFilesUnionSilently(): void
    {
        $map = MatcherMap::fromConfigFiles([self::CONFIG, self::CONFIG]);

        expect($map->has('toHaveDigestLength'))->because('identical declarations from multiple files merge without an error')->toBeTrue();
    }

    #[Test]
    public function relativeConfigurationPathsResolveFromTheWorkingDirectory(): void
    {
        $workingDirectory = \getcwd();

        expect($workingDirectory)
            ->because('The matcher configuration fixture MUST have a working directory.')
            ->toBeString();
        expect(\str_starts_with(self::CONFIG, $workingDirectory . '/'))
            ->because('The matcher configuration fixture MUST be below the current working directory.')
            ->toBeTrue();

        $relativeConfig = \substr(self::CONFIG, \strlen($workingDirectory) + 1);
        $map = MatcherMap::fromConfigFiles([$relativeConfig]);

        expect($map->has('toHaveDigestLength'))
            ->because('relative matcher configuration paths resolve from the working directory')
            ->toBeTrue();
    }

    #[Test]
    public function pluginsWithoutExpectationMatchersAreIgnored(): void
    {
        $map = MatcherMap::fromConfigFiles([self::NON_EXPECTATION_CONFIG]);

        expect($map->names())
            ->because('plugins without expectation matchers do not add static methods')
            ->toBe([]);
    }

    #[Test]
    public function conflictingSignaturesAreRefused(): void
    {
        expect()->calling(
            static fn(): MatcherMap => MatcherMap::fromConfigFiles([self::CONFIG, self::CONFLICTING_CONFIG]),
        )->because('conflicting signatures are refused')->toThrow(MatcherMapError::class);
    }

    #[Test]
    public function anUnknownMatcherNameFailsLoudly(): void
    {
        $map = MatcherMap::fromConfigFiles([]);

        expect()->calling(static fn(): array => $map->parameters('toBeMissing'))
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

        expect(MatcherMap::typeName($parameter->getType()))
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
        yield 'intersection' => ['intersection', '\\Countable&\\Iterator'];
    }
}
