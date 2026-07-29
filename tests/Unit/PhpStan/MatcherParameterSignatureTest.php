<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\MatcherMap;

final class MatcherParameterSignatureTest
{
    #[Test]
    #[DataSet('parameterSignatures')]
    public function rendersOptionalAndVariadicParameters(string $method, string $expected): void
    {
        $parameter = new \ReflectionMethod(self::class, $method)->getParameters()[0];

        Expect::that(MatcherMap::parameterSignature($parameter, 'null'))
            ->because('generated matcher signatures MUST distinguish optional and variadic parameters')
            ->toBe($expected);
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
