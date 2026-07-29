<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\MatcherMap;

final class MatcherMapNullableTypeTest
{
    #[Test]
    #[DataSet('nullableNamedTypes')]
    public function nullableNamedTypesRenderForGeneratedSignatures(string $method, string $expected): void
    {
        $parameter = new \ReflectionMethod(self::class, $method)->getParameters()[0];

        Expect::that(MatcherMap::typeName($parameter->getType()))
            ->because('nullable named types MUST render valid generated signatures')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function nullableNamedTypes(): iterable
    {
        yield 'nullable string' => ['acceptsNullableString', '?string'];
        yield 'mixed already includes null' => ['acceptsMixed', 'mixed'];
        yield 'standalone null has no prefix' => ['acceptsNull', 'null'];
    }

    public function acceptsNullableString(?string $value): ?string
    {
        return $value;
    }

    public function acceptsMixed(mixed $value): mixed
    {
        return $value;
    }

    public function acceptsNull(null $value): null
    {
        return $value;
    }
}
