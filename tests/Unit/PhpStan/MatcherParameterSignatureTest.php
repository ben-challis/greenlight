<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\MatcherMap;

final class MatcherParameterSignatureTest
{
    private const string SCOPED_CONFIG = __DIR__ . '/../../Fixture/PhpStanScopedMatcher/greenlight.php';

    #[Test]
    #[DataSet('parameterSignatures')]
    public function rendersOptionalAndVariadicParameters(string $method, string $expected): void
    {
        $parameter = new \ReflectionMethod(self::class, $method)->getParameters()[0];

        Expect::that(MatcherMap::parameterSignature($parameter, 'null'))
            ->because('generated matcher signatures MUST distinguish optional and variadic parameters')
            ->toBe($expected);
    }

    #[Test]
    public function resolvesRelativeTypesAgainstTheClosureScope(): void
    {
        $map = MatcherMap::fromConfigFiles([self::SCOPED_CONFIG]);
        $selfParameter = $map->parameters('toAcceptSelfArgument')[0];
        $parentParameter = $map->parameters('toAcceptParentArgument')[0];

        Expect::that(MatcherMap::parameterSignature($selfParameter, 'null'))
            ->because('self uses the matcher closure scope')
            ->toBe(
                '\\Greenlight\\Tests\\Fixture\\PhpStanScopedMatcher\\ScopedMatcherExtension $other',
            );
        Expect::that(MatcherMap::parameterSignature($parentParameter, 'null'))
            ->because('parent uses the matcher closure parent scope')
            ->toBe(
                '\\Greenlight\\Tests\\Fixture\\PhpStanScopedMatcher\\MatcherSubject $other',
            );
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function parameterSignatures(): iterable
    {
        yield 'optional' => ['optionalParameter', 'int $limit = null'];
        yield 'variadic' => ['variadicParameter', 'string ...$values'];
    }

    public function optionalParameter(int $limit = 1): int
    {
        return $limit;
    }

    public function variadicParameter(string ...$values): string
    {
        return \implode('', $values);
    }
}
